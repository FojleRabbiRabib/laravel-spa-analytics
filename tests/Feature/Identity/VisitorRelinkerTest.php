<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\Fingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Event;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Session;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorFingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorLink;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\FingerprintMatcher;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\VisitorRelinker;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class VisitorRelinkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('spa-analytics.identity.relink', true);
    }

    private function fingerprint(string $stable = 'stable', ?string $canvas = 'canvas'): Fingerprint
    {
        return new Fingerprint($stable, $canvas, null, null, null);
    }

    private function identity(string $id = 'new', ?Fingerprint $fingerprint = null): VisitorIdentity
    {
        return new VisitorIdentity($id, IdentitySource::Cookie, $fingerprint ?? $this->fingerprint());
    }

    private function known(string $id, string $stable = 'stable', ?string $canvas = 'canvas'): VisitorFingerprint
    {
        return VisitorFingerprint::factory()->create([
            'visitor_id' => $id,
            'stable_hash' => $stable,
            'canvas_hash' => $canvas,
            'audio_hash' => null,
            'webgl_hash' => null,
            'tls_hash' => null,
        ]);
    }

    private function relink(?VisitorIdentity $identity = null): ?string
    {
        return app(VisitorRelinker::class)->relink($identity ?? $this->identity());
    }

    public function test_a_single_match_adopts_the_old_id_and_moves_history(): void
    {
        $this->known('old');
        Event::factory()->count(2)->create(['visitor_id' => 'new']);
        Event::factory()->create(['visitor_id' => 'old']);
        Session::factory()->create(['visitor_id' => 'new', 'is_new_visitor' => true]);

        $this->assertSame('old', $this->relink());

        $this->assertSame(3, Event::query()->where('visitor_id', 'old')->count());
        $this->assertSame(0, Event::query()->where('visitor_id', 'new')->count());

        $session = Session::query()->sole();
        $this->assertSame('old', $session->visitor_id);
        $this->assertFalse($session->is_new_visitor);
    }

    public function test_a_link_is_recorded_and_older_links_are_repointed(): void
    {
        $this->known('old');
        VisitorLink::factory()->create(['visitor_id' => 'ancient', 'linked_to' => 'new']);

        $this->relink();

        $this->assertSame('old', VisitorLink::query()->where('visitor_id', 'new')->value('linked_to'));
        $this->assertSame('old', VisitorLink::query()->where('visitor_id', 'ancient')->value('linked_to'));
    }

    public function test_two_matches_are_ambiguous_and_change_nothing(): void
    {
        $this->known('old-a');
        $this->known('old-b');
        Event::factory()->create(['visitor_id' => 'new']);

        $this->assertNull($this->relink());

        $this->assertSame(1, Event::query()->where('visitor_id', 'new')->count());
        $this->assertSame(0, VisitorLink::query()->count());
    }

    public function test_no_known_visitor_returns_null(): void
    {
        $this->assertNull($this->relink());
    }

    public function test_equal_stable_hash_without_a_matching_volatile_hash_returns_null(): void
    {
        $this->known('old', 'stable', 'different-canvas');

        $this->assertNull($this->relink());
    }

    public function test_a_different_stable_hash_returns_null(): void
    {
        $this->known('old', 'other-stable');

        $this->assertNull($this->relink());
    }

    public function test_an_already_identified_visitor_is_not_relinked(): void
    {
        $this->known('old');
        $this->known('new', 'unrelated', 'unrelated');

        $this->assertNull($this->relink());
    }

    public function test_relinking_can_be_turned_off(): void
    {
        config()->set('spa-analytics.identity.relink', false);
        $this->known('old');

        $this->assertNull($this->relink());
    }

    public function test_more_than_fifty_candidates_count_as_ambiguous(): void
    {
        VisitorFingerprint::factory()->count(51)->create(['stable_hash' => 'stable', 'canvas_hash' => 'canvas']);

        $this->assertNull($this->relink());
    }

    public function test_an_identity_without_a_fingerprint_returns_null(): void
    {
        $this->known('old');

        $this->assertNull($this->relink(new VisitorIdentity('new', IdentitySource::Cookie)));
    }

    public function test_the_relink_waits_on_the_visitor_lock(): void
    {
        $this->known('old');
        Cache::lock('session:new', 10)->get();

        $relinker = new VisitorRelinker(app(FingerprintMatcher::class), 0);

        $this->expectException(LockTimeoutException::class);

        $relinker->relink($this->identity());
    }
}
