<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\VisitorIdentified;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support\PayloadEncoder;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class IdentifyEndpointTest extends TestCase
{
    private const COOKIE = 'spa_analytics_vid';

    private const VISITOR = '11111111-1111-4111-8111-111111111111';

    protected function disableMiddlewareRegistration($app): void
    {
        $app['config']->set('laravel-spa-analytics.identity.register_middleware', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function handshake(string $visitor = self::VISITOR): array
    {
        return $this->withCredentials()->withCookie(self::COOKIE, $visitor)
            ->postJson(route('spa-analytics.identity.handshake'))
            ->assertOk()
            ->json();
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function identify(string $body, string $visitor = self::VISITOR, array $headers = []): TestResponse
    {
        $this->withCookie(self::COOKIE, $visitor);

        $server = ['CONTENT_TYPE' => 'application/octet-stream'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call(
            'POST',
            route('spa-analytics.identity.identify'),
            [],
            $this->prepareCookiesForRequest(),
            [],
            $server,
            $body,
        );
    }

    /**
     * @param  array<string, mixed>  $handshake
     * @param  array<string, int|string>  $overrides
     */
    private function validBody(array $handshake, array $overrides = []): string
    {
        return PayloadEncoder::body(
            PayloadEncoder::plaintext(PayloadEncoder::sample($overrides)),
            $handshake['nonce'],
            $handshake['key'],
        );
    }

    public function test_handshake_returns_nonce_key_and_expiry(): void
    {
        $handshake = $this->handshake();

        $this->assertArrayHasKey('nonce', $handshake);
        $this->assertArrayHasKey('key', $handshake);
        $this->assertGreaterThan(now()->timestamp, $handshake['expires_at']);
    }

    public function test_valid_payload_returns_identity_only(): void
    {
        $response = $this->identify($this->validBody($this->handshake()));

        $response->assertOk()->assertExactJson([
            'id' => self::VISITOR,
            'source' => IdentitySource::Cookie->value,
        ]);
    }

    public function test_fingerprint_is_bound_after_identify(): void
    {
        $this->identify($this->validBody($this->handshake()))->assertOk();

        $identity = app(VisitorIdentity::class);

        $this->assertSame(64, strlen($identity->fingerprint->stableHash));
        $this->assertNotNull($identity->fingerprint->canvasHash);
        $this->assertNotNull($identity->fingerprint->webglHash);
        $this->assertNull($identity->fingerprint->tlsHash);
    }

    public function test_visitor_identified_event_carries_the_fingerprint(): void
    {
        Event::fake([VisitorIdentified::class]);

        $this->identify($this->validBody($this->handshake()))->assertOk();

        Event::assertDispatched(
            VisitorIdentified::class,
            fn (VisitorIdentified $event): bool => $event->identity->id === self::VISITOR
                && $event->identity->fingerprint !== null,
        );
    }

    public function test_ja4_header_is_hashed_when_configured(): void
    {
        config()->set('laravel-spa-analytics.identity.tls_fingerprint_header', 'X-JA4');

        $this->identify($this->validBody($this->handshake()), headers: ['X-JA4' => 't13d1516h2_abc'])->assertOk();

        $this->assertSame(hash('sha256', 'tls|t13d1516h2_abc'), app(VisitorIdentity::class)->fingerprint->tlsHash);
    }

    public function test_replay_is_rejected_with_a_generic_error(): void
    {
        $body = $this->validBody($this->handshake());

        $this->identify($body)->assertOk();
        $this->identify($body)->assertStatus(422)->assertExactJson(['message' => 'Invalid payload']);
    }

    public function test_garbage_and_schema_violations_are_rejected(): void
    {
        $this->identify('garbage')->assertStatus(422);
        $this->identify($this->validBody($this->handshake(), ['timezone' => str_repeat('x', 65)]))->assertStatus(422);
    }

    public function test_nonce_from_another_visitor_is_rejected(): void
    {
        $handshake = $this->handshake();

        $this->identify($this->validBody($handshake), '22222222-2222-4222-8222-222222222222')->assertStatus(422);
    }

    public function test_rate_limit_applies(): void
    {
        config()->set('laravel-spa-analytics.identity.rate_limit_per_minute', 2);

        $this->postJson(route('spa-analytics.identity.handshake'))->assertOk();
        $this->postJson(route('spa-analytics.identity.handshake'))->assertOk();
        $this->postJson(route('spa-analytics.identity.handshake'))->assertStatus(429);
    }

    #[DefineEnvironment('disableMiddlewareRegistration')]
    public function test_endpoints_work_when_middleware_auto_registration_is_off(): void
    {
        $this->identify($this->validBody($this->handshake()))->assertOk();
    }

    public function test_endpoints_are_csrf_protected_and_throttled(): void
    {
        foreach (['handshake', 'identify'] as $name) {
            $route = app('router')->getRoutes()->getByName('spa-analytics.identity.'.$name);
            $middleware = app('router')->gatherRouteMiddleware($route);

            $this->assertContains(PreventRequestForgery::class, $middleware);
            $this->assertContains('Illuminate\Routing\Middleware\ThrottleRequests:spa-analytics-identity', $middleware);
        }
    }

    public function test_handshake_sets_the_visitor_cookie_for_a_new_visitor(): void
    {
        $response = $this->postJson(route('spa-analytics.identity.handshake'))->assertOk();

        $this->assertNotNull($response->getCookie(self::COOKIE, false));
    }
}
