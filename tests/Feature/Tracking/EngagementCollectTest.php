<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

class EngagementCollectTest extends TestCase
{
    private const VISITOR = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:00:00');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.tracking.write_mode', 'sync');
    }

    private function engagement(mixed $seconds, string $path = '/pricing'): TestResponse
    {
        return $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0'])
            ->postJson(route('spa-analytics.collect'), ['events' => [['kind' => 'engagement', 'path' => $path, 'seconds' => $seconds]]]);
    }

    private function activeSession(): AnalyticsSession
    {
        return AnalyticsSession::factory()->create([
            'visitor_id' => self::VISITOR,
            'started_at' => Carbon::now()->subMinutes(2),
            'last_seen_at' => Carbon::now()->subMinute(),
            'exit_path' => '/last',
        ]);
    }

    public function test_the_seconds_are_stored_on_an_engagement_event_for_the_page(): void
    {
        $this->engagement(42)->assertNoContent();

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(EventType::Engagement, $event->type);
        $this->assertSame(42, $event->engaged_seconds);
        $this->assertSame('/pricing', $event->path);
        $this->assertNull($event->status);
        $this->assertNull($event->name);
    }

    public function test_fractions_are_floored_and_a_forged_value_is_capped_at_thirty_minutes(): void
    {
        $this->engagement(12.9)->assertNoContent();
        $this->engagement('45')->assertNoContent();
        $this->engagement(99999)->assertNoContent();

        $this->assertSame([12, 45, 1800], AnalyticsEvent::query()->orderBy('id')->pluck('engaged_seconds')->all());
    }

    public function test_values_below_one_second_and_values_that_are_not_numbers_are_dropped(): void
    {
        foreach ([0, 0.9, -5, 'long', null, [30], true] as $seconds) {
            $this->engagement($seconds)->assertNoContent();
        }

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_it_attaches_to_the_active_session_without_extending_or_opening_one(): void
    {
        $session = $this->activeSession();
        $lastSeen = $session->last_seen_at->toDateTimeString();

        $this->engagement(30)->assertNoContent();

        $fresh = $session->fresh();

        $this->assertSame($session->id, AnalyticsEvent::query()->sole()->session_id);
        $this->assertSame($lastSeen, $fresh->last_seen_at->toDateTimeString());
        $this->assertSame('/last', $fresh->exit_path);
        $this->assertSame(1, $fresh->page_views);
        $this->assertSame(1, AnalyticsSession::query()->count());
    }

    public function test_without_an_active_session_none_is_opened(): void
    {
        $this->engagement(30)->assertNoContent();

        $this->assertNull(AnalyticsEvent::query()->sole()->session_id);
        $this->assertSame(0, AnalyticsSession::query()->count());
    }

    public function test_an_excluded_path_is_skipped(): void
    {
        config()->set('spa-analytics.tracking.excluded_paths', ['reset-password/*']);

        $this->engagement(30, '/reset-password/abc')->assertNoContent();

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }
}
