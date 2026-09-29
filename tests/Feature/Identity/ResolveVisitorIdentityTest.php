<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware\ResolveVisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Str;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ResolveVisitorIdentityTest extends TestCase
{
    private const COOKIE = 'spa_analytics_vid';

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/probe', fn (VisitorIdentity $identity) => response()->json([
            'id' => $identity->id,
            'source' => $identity->source,
        ]));

        $router->middleware('web')->get('/plain', fn () => 'ok');
    }

    protected function disableMiddlewareRegistration($app): void
    {
        $app['config']->set('laravel-spa-analytics.identity.register_middleware', false);
    }

    public function test_first_visit_mints_a_uuid_and_sets_the_cookie(): void
    {
        $response = $this->get('/probe');

        $response->assertOk()->assertJsonPath('source', IdentitySource::Generated->value);
        $this->assertTrue(Str::isUuid($response->json('id')));
        $this->assertSame($response->json('id'), $response->getCookie(self::COOKIE)->getValue());
    }

    public function test_valid_cookie_is_reused(): void
    {
        $uuid = (string) Str::uuid();

        $response = $this->withCookie(self::COOKIE, $uuid)->get('/probe');

        $response->assertOk()
            ->assertJsonPath('id', $uuid)
            ->assertJsonPath('source', IdentitySource::Cookie->value);
    }

    public function test_invalid_cookie_is_replaced(): void
    {
        $response = $this->withCookie(self::COOKIE, 'not-a-uuid')->get('/probe');

        $response->assertJsonPath('source', IdentitySource::Generated->value);
        $this->assertTrue(Str::isUuid($response->json('id')));
    }

    public function test_cookie_attributes(): void
    {
        $cookie = $this->get('/probe')->getCookie(self::COOKIE, false);

        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertEqualsWithDelta(now()->addDays(365)->timestamp, $cookie->getExpiresTime(), 10);
    }

    public function test_disabled_config_is_a_no_op(): void
    {
        config()->set('laravel-spa-analytics.enabled', false);

        $this->get('/plain')->assertOk()->assertCookieMissing(self::COOKIE);
    }

    public function test_middleware_is_registered_on_the_web_group(): void
    {
        $this->assertContains(ResolveVisitorIdentity::class, $this->webGroup());
    }

    #[DefineEnvironment('disableMiddlewareRegistration')]
    public function test_middleware_registration_can_be_turned_off(): void
    {
        $this->assertNotContains(ResolveVisitorIdentity::class, $this->webGroup());
    }

    /**
     * @return array<int, string>
     */
    private function webGroup(): array
    {
        return $this->app->make(Kernel::class)->getMiddlewareGroups()['web'] ?? [];
    }
}
