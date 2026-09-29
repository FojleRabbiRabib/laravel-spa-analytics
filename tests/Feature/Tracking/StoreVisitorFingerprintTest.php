<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\Fingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\VisitorIdentified;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorFingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support\PayloadEncoder;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class StoreVisitorFingerprintTest extends TestCase
{
    private const VISITOR = '11111111-1111-4111-8111-111111111111';

    protected function disableTracking($app): void
    {
        $app['config']->set('laravel-spa-analytics.enabled', false);
    }

    private function identity(?Fingerprint $fingerprint, string $id = self::VISITOR): VisitorIdentity
    {
        return new VisitorIdentity($id, IdentitySource::Cookie, $fingerprint);
    }

    private function fingerprint(string $canvas = 'canvas-a'): Fingerprint
    {
        return new Fingerprint('stable-a', $canvas, 'audio-a', 'webgl-a', 'tls-a');
    }

    public function test_dispatching_the_event_creates_the_fingerprint_row(): void
    {
        VisitorIdentified::dispatch($this->identity($this->fingerprint()));

        $row = VisitorFingerprint::query()->sole();

        $this->assertSame(self::VISITOR, $row->visitor_id);
        $this->assertSame('stable-a', $row->stable_hash);
        $this->assertSame('canvas-a', $row->canvas_hash);
        $this->assertSame('audio-a', $row->audio_hash);
        $this->assertSame('webgl-a', $row->webgl_hash);
        $this->assertSame('tls-a', $row->tls_hash);
    }

    public function test_repeat_dispatch_keeps_one_row_and_preserves_first_seen(): void
    {
        $this->travelTo(now()->startOfSecond());
        VisitorIdentified::dispatch($this->identity($this->fingerprint()));
        $firstSeen = VisitorFingerprint::query()->sole()->first_seen_at;

        $this->travel(2)->hours();
        VisitorIdentified::dispatch($this->identity($this->fingerprint()));

        $row = VisitorFingerprint::query()->sole();

        $this->assertTrue($row->first_seen_at->equalTo($firstSeen));
        $this->assertTrue($row->last_seen_at->equalTo(now()->startOfSecond()));
        $this->assertTrue($row->last_seen_at->greaterThan($row->first_seen_at));
    }

    public function test_changed_volatile_hashes_are_updated(): void
    {
        VisitorIdentified::dispatch($this->identity($this->fingerprint('canvas-a')));
        VisitorIdentified::dispatch($this->identity($this->fingerprint('canvas-b')));

        $this->assertSame('canvas-b', VisitorFingerprint::query()->sole()->canvas_hash);
    }

    public function test_identity_without_a_fingerprint_writes_nothing(): void
    {
        VisitorIdentified::dispatch($this->identity(null));

        $this->assertSame(0, VisitorFingerprint::query()->count());
    }

    public function test_the_real_identify_endpoint_stores_the_fingerprint(): void
    {
        $handshake = $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)
            ->postJson(route('spa-analytics.identity.handshake'))
            ->assertOk()
            ->json();

        $body = PayloadEncoder::body(
            PayloadEncoder::plaintext(PayloadEncoder::sample()),
            $handshake['nonce'],
            $handshake['key'],
        );

        $this->withCookie('spa_analytics_vid', self::VISITOR)->call(
            'POST',
            route('spa-analytics.identity.identify'),
            [],
            $this->prepareCookiesForRequest(),
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            $body,
        )->assertOk();

        $row = VisitorFingerprint::query()->sole();

        $this->assertSame(self::VISITOR, $row->visitor_id);
        $this->assertSame(64, strlen($row->stable_hash));
    }

    #[DefineEnvironment('disableTracking')]
    public function test_disabled_config_registers_no_listener(): void
    {
        $this->assertFalse(Event::hasListeners(VisitorIdentified::class));
    }
}
