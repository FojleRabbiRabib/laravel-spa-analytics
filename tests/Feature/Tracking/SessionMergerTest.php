<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ViewportSize;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\SessionMerger;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class SessionMergerTest extends TestCase
{
    private const VISITOR = 'visitor';

    private function at(string $time): Carbon
    {
        return Carbon::parse('2026-03-02 '.$time);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function visit(string $start, string $end, array $attributes = []): AnalyticsSession
    {
        return AnalyticsSession::factory()->create([
            'visitor_id' => self::VISITOR,
            'started_at' => $this->at($start),
            'last_seen_at' => $this->at($end),
            'is_new_visitor' => false,
            ...$attributes,
        ]);
    }

    /**
     * @param  list<AnalyticsSession>  $moved
     */
    private function merge(array $moved): void
    {
        app(SessionMerger::class)->merge(self::VISITOR, array_map(fn (AnalyticsSession $s): int => $s->id, $moved));
    }

    public function test_a_moved_session_inside_the_timeout_gap_folds_into_the_earlier_one(): void
    {
        $adopted = $this->visit('09:00:00', '09:10:00', ['entry_path' => '/a', 'exit_path' => '/b', 'page_views' => 2, 'is_new_visitor' => true]);
        $moved = $this->visit('09:30:00', '09:40:00', ['entry_path' => '/c', 'exit_path' => '/d', 'page_views' => 3]);
        AnalyticsEvent::factory()->create(['visitor_id' => self::VISITOR, 'session_id' => $moved->id]);

        $this->merge([$moved]);

        $survivor = AnalyticsSession::query()->sole();
        $this->assertSame($adopted->id, $survivor->id);
        $this->assertSame('/a', $survivor->entry_path);
        $this->assertSame('/d', $survivor->exit_path);
        $this->assertSame(5, $survivor->page_views);
        $this->assertEquals($this->at('09:00:00'), $survivor->started_at);
        $this->assertEquals($this->at('09:40:00'), $survivor->last_seen_at);
        $this->assertTrue($survivor->is_new_visitor);
        $this->assertSame($adopted->id, AnalyticsEvent::query()->sole()->session_id);
    }

    public function test_the_survivor_takes_the_viewport_of_an_absorbed_session_only_when_it_has_none(): void
    {
        $this->visit('09:00:00', '09:10:00');
        $moved = $this->visit('09:30:00', '09:40:00', ['viewport' => ViewportSize::Lg]);

        $this->merge([$moved]);

        $this->assertSame(ViewportSize::Lg, AnalyticsSession::query()->sole()->viewport);

        AnalyticsSession::query()->delete();
        $this->visit('09:00:00', '09:10:00', ['viewport' => ViewportSize::Xs]);
        $moved = $this->visit('09:30:00', '09:40:00', ['viewport' => ViewportSize::Lg]);

        $this->merge([$moved]);

        $this->assertSame(ViewportSize::Xs, AnalyticsSession::query()->sole()->viewport);
    }

    public function test_a_gap_of_exactly_the_timeout_merges(): void
    {
        config()->set('spa-analytics.sessions.timeout_minutes', 30);
        $this->visit('09:00:00', '09:10:00');
        $moved = $this->visit('09:40:00', '09:45:00');

        $this->merge([$moved]);

        $this->assertSame(1, AnalyticsSession::query()->count());
    }

    public function test_a_gap_beyond_the_timeout_stays_separate(): void
    {
        config()->set('spa-analytics.sessions.timeout_minutes', 30);
        $this->visit('09:00:00', '09:10:00');
        $moved = $this->visit('09:40:01', '09:45:00');

        $this->merge([$moved]);

        $this->assertSame(2, AnalyticsSession::query()->count());
    }

    public function test_a_moved_session_bridging_two_sessions_collapses_all_three(): void
    {
        $first = $this->visit('09:00:00', '09:10:00', ['page_views' => 1]);
        $moved = $this->visit('09:35:00', '09:45:00', ['page_views' => 1]);
        $last = $this->visit('10:10:00', '10:20:00', ['page_views' => 1, 'exit_path' => '/end']);

        $this->merge([$moved]);

        $survivor = AnalyticsSession::query()->sole();
        $this->assertSame($first->id, $survivor->id);
        $this->assertSame(3, $survivor->page_views);
        $this->assertSame('/end', $survivor->exit_path);
        $this->assertEquals($this->at('10:20:00'), $survivor->last_seen_at);
        $this->assertModelMissing($last);
    }

    public function test_the_earliest_session_keeps_its_attribution(): void
    {
        $this->visit('09:00:00', '09:10:00', ['referrer_host' => 'google.com', 'utm_source' => 'news', 'is_bot' => false]);
        $moved = $this->visit('09:20:00', '09:30:00', ['referrer_host' => 'other.test', 'utm_source' => 'ads', 'is_bot' => true]);

        $this->merge([$moved]);

        $survivor = AnalyticsSession::query()->sole();
        $this->assertSame('google.com', $survivor->referrer_host);
        $this->assertSame('news', $survivor->utm_source);
        $this->assertFalse($survivor->is_bot);
    }

    public function test_sessions_that_are_far_apart_are_never_touched(): void
    {
        $this->visit('06:00:00', '06:10:00');
        $this->visit('09:00:00', '09:10:00');
        $moved = $this->visit('12:00:00', '12:10:00');

        $this->merge([$moved]);

        $this->assertSame(3, AnalyticsSession::query()->count());
    }

    public function test_a_chain_without_a_moved_session_inside_the_window_is_left_alone(): void
    {
        $firstMoved = $this->visit('08:00:00', '08:05:00');
        $lastMoved = $this->visit('12:00:00', '12:05:00');
        $this->visit('10:00:00', '10:05:00');
        $this->visit('10:20:00', '10:25:00');

        $this->merge([$firstMoved, $lastMoved]);

        $this->assertSame(4, AnalyticsSession::query()->count());
    }

    public function test_nothing_happens_without_moved_sessions(): void
    {
        $this->visit('09:00:00', '09:10:00');
        $this->visit('09:20:00', '09:30:00');

        app(SessionMerger::class)->merge(self::VISITOR, []);

        $this->assertSame(2, AnalyticsSession::query()->count());
    }

    public function test_other_visitors_are_not_merged(): void
    {
        $this->visit('09:00:00', '09:10:00', ['visitor_id' => 'someone-else']);
        $moved = $this->visit('09:20:00', '09:30:00');

        $this->merge([$moved]);

        $this->assertSame(2, AnalyticsSession::query()->count());
    }
}
