<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\Fingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\VisitorRelinked;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorFingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorLink;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\FingerprintMatcher;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\VisitorRelinker;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\SessionMerger;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

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
        AnalyticsEvent::factory()->count(2)->create(['visitor_id' => 'new']);
        AnalyticsEvent::factory()->create(['visitor_id' => 'old']);
        AnalyticsSession::factory()->create(['visitor_id' => 'new', 'is_new_visitor' => true]);

        $this->assertSame('old', $this->relink());

        $this->assertSame(3, AnalyticsEvent::query()->where('visitor_id', 'old')->count());
        $this->assertSame(0, AnalyticsEvent::query()->where('visitor_id', 'new')->count());

        $session = AnalyticsSession::query()->sole();
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
        AnalyticsEvent::factory()->create(['visitor_id' => 'new']);

        $this->assertNull($this->relink());

        $this->assertSame(1, AnalyticsEvent::query()->where('visitor_id', 'new')->count());
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

        $relinker = new VisitorRelinker(app(FingerprintMatcher::class), app(SessionMerger::class), 0);

        $this->expectException(LockTimeoutException::class);

        $relinker->relink($this->identity());
    }

    public function test_the_relink_waits_on_the_adopted_visitor_lock(): void
    {
        $this->known('old');
        Cache::lock('session:old', 10)->get();

        $relinker = new VisitorRelinker(app(FingerprintMatcher::class), app(SessionMerger::class), 0);

        $this->expectException(LockTimeoutException::class);

        $relinker->relink($this->identity());
    }

    public function test_relinking_the_same_visitor_again_returns_the_link_without_moving_or_announcing_again(): void
    {
        Event::fake([VisitorRelinked::class]);
        $this->known('old');
        AnalyticsEvent::factory()->create(['visitor_id' => 'new']);

        $this->assertSame('old', $this->relink());
        AnalyticsEvent::factory()->create(['visitor_id' => 'new']);

        $this->assertSame('old', $this->relink());

        Event::assertDispatchedTimes(VisitorRelinked::class, 1);
        $this->assertSame(1, AnalyticsEvent::query()->where('visitor_id', 'new')->count());
        $this->assertSame(1, VisitorLink::query()->count());
    }

    public function test_a_relink_announces_the_previous_and_adopted_ids(): void
    {
        Event::fake([VisitorRelinked::class]);
        $this->known('old');

        $this->relink();

        Event::assertDispatched(
            VisitorRelinked::class,
            fn (VisitorRelinked $event): bool => $event->previousId === 'new' && $event->visitorId === 'old',
        );
    }

    public function test_a_moved_session_inside_the_timeout_gap_merges_into_the_adopted_visitors_session(): void
    {
        $this->known('old');
        $start = Carbon::parse('2026-03-02 09:00:00');
        AnalyticsSession::factory()->create(['visitor_id' => 'old', 'started_at' => $start, 'last_seen_at' => $start->copy()->addMinutes(10)]);
        $moved = AnalyticsSession::factory()->create(['visitor_id' => 'new', 'started_at' => $start->copy()->addMinutes(20), 'last_seen_at' => $start->copy()->addMinutes(25)]);
        $event = AnalyticsEvent::factory()->create(['visitor_id' => 'new', 'session_id' => $moved->id]);

        $this->relink();

        $session = AnalyticsSession::query()->sole();
        $this->assertSame('old', $session->visitor_id);
        $this->assertSame(2, $session->page_views);
        $this->assertSame($session->id, $event->fresh()->session_id);
    }

    public function test_custom_events_move_with_the_visitor_and_keep_their_details(): void
    {
        $this->known('old');
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'new', 'name' => 'purchase', 'value' => 10, 'properties' => ['plan' => 'pro']]);

        $this->relink();

        $event = AnalyticsEvent::query()->sole();
        $this->assertSame('old', $event->visitor_id);
        $this->assertSame('purchase', $event->name);
        $this->assertSame(['plan' => 'pro'], $event->properties);
    }
}
