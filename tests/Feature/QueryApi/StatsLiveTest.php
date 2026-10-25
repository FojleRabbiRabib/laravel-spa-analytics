<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\FunnelStep;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Query\StatsReport;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupBuilder;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsLiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function today(): StatsReport
    {
        return Stats::between(Carbon::parse('2026-03-10 00:00:00'), Carbon::parse('2026-03-11 00:00:00'));
    }

    private function pageView(string $visitor, string $at, string $path = '/pricing', bool $bot = false, ?AnalyticsSession $session = null): AnalyticsEvent
    {
        return AnalyticsEvent::factory()->create([
            'visitor_id' => $visitor,
            'occurred_at' => Carbon::parse($at),
            'path' => $path,
            'is_bot' => $bot,
            'session_id' => $session?->id,
        ]);
    }

    private function openSession(string $visitor, string $at, array $attributes = []): AnalyticsSession
    {
        return AnalyticsSession::factory()->create([
            'visitor_id' => $visitor,
            'started_at' => Carbon::parse($at),
            'last_seen_at' => Carbon::parse($at)->addMinutes(5),
            'page_views' => 1,
            'is_new_visitor' => true,
            ...$attributes,
        ]);
    }

    private function rollUp(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-10 00:00:00')->toImmutable());
    }

    public function test_a_fresh_site_with_no_rollups_shows_the_open_hour(): void
    {
        $this->pageView('a', '2026-03-10 12:10:00');
        $this->pageView('b', '2026-03-10 12:20:00');

        $this->assertSame(0, $this->today()->withoutLive()->summary()->pageViews);

        $summary = $this->today()->withLive()->summary();

        $this->assertSame(2, $summary->pageViews);
        $this->assertSame(2, $summary->users);
        $this->assertSame('2026-03-10 12:30:00', $summary->through->toDateTimeString());
    }

    public function test_the_rolled_up_hours_and_the_live_tail_are_added_together(): void
    {
        $this->pageView('a', '2026-03-10 09:10:00');
        $this->rollUp();
        $this->pageView('b', '2026-03-10 12:20:00');

        $this->assertSame(1, $this->today()->withoutLive()->summary()->pageViews);
        $this->assertSame(2, $this->today()->withLive()->summary()->pageViews);
    }

    public function test_an_event_exactly_at_the_end_of_the_rolled_up_hours_is_counted_once(): void
    {
        $this->pageView('a', '2026-03-10 09:10:00');
        $this->rollUp();
        $through = Stats::between(Carbon::parse('2026-03-10'), Carbon::parse('2026-03-11'))->withoutLive()->summary()->through;
        $this->assertSame('2026-03-10 12:00:00', $through?->toDateTimeString());
        $this->pageView('b', '2026-03-10 12:00:00');

        $this->assertSame(2, $this->today()->withLive()->summary()->pageViews);
    }

    public function test_when_the_rollup_is_further_behind_than_the_cap_none_of_the_live_part_is_read_and_it_says_so(): void
    {
        config()->set('spa-analytics.stats.live_max_hours', 6);
        Carbon::setTestNow('2026-03-10 02:30:00');
        $this->pageView('a', '2026-03-10 01:10:00');
        $this->rollUp();
        Carbon::setTestNow('2026-03-10 12:30:00');
        $this->pageView('old', '2026-03-10 03:10:00');
        $this->pageView('recent', '2026-03-10 11:10:00');

        $summary = $this->today()->withLive()->summary();

        $this->assertSame(1, $summary->pageViews);
        $this->assertSame(1, $summary->users);
        $this->assertTrue($summary->incomplete);
        $this->assertSame('2026-03-10 02:00:00', $summary->through?->toDateTimeString());
    }

    public function test_a_rollup_that_lands_between_two_calls_does_not_count_an_hour_twice(): void
    {
        $this->pageView('a', '2026-03-10 09:10:00');
        Carbon::setTestNow('2026-03-10 11:30:00');
        $this->rollUp();
        $this->pageView('b', '2026-03-10 11:10:00');
        $report = $this->today()->withLive();
        $this->assertSame(2, $report->summary()->pageViews);

        Carbon::setTestNow('2026-03-10 12:30:00');
        $this->rollUp();
        $points = $report->timeseries(RollupPeriod::Hour);

        $this->assertSame(2, array_sum(array_map(fn ($point) => $point->pageViews, $points)));
    }

    public function test_a_stored_whole_day_and_a_partial_day_tail_are_added_together(): void
    {
        $this->pageView('a', '2026-03-09 10:00:00');
        $this->pageView('b', '2026-03-10 09:10:00');
        app(RollupRunner::class)->run(Carbon::parse('2026-03-10 11:30:00')->toImmutable(), Carbon::parse('2026-03-09 00:00:00')->toImmutable());
        $this->pageView('c', '2026-03-10 12:10:00');

        $report = Stats::between(Carbon::parse('2026-03-09'), Carbon::parse('2026-03-11'))->withLive();
        $days = $report->timeseries(RollupPeriod::Day);

        $this->assertSame(3, $report->summary()->pageViews);
        $this->assertSame([1, 2], array_map(fn ($point) => $point->pageViews, $days));
    }

    public function test_a_tail_that_crosses_midnight_is_counted(): void
    {
        Carbon::setTestNow('2026-03-10 00:30:00');
        $this->pageView('late', '2026-03-09 23:50:00');
        $this->pageView('early', '2026-03-10 00:10:00');

        $report = Stats::between(Carbon::parse('2026-03-09'), Carbon::parse('2026-03-11'))->withLive();

        $this->assertSame(2, $report->summary()->pageViews);
        $this->assertSame([1, 1], array_map(fn ($point) => $point->pageViews, $report->timeseries(RollupPeriod::Day)));
    }

    public function test_a_fresh_site_with_a_long_range_counts_what_exists(): void
    {
        $this->pageView('a', '2026-03-10 12:10:00');

        $report = Stats::between(Carbon::parse('2026-03-03'), Carbon::parse('2026-03-11'))->withLive();

        $this->assertSame(1, $report->summary()->pageViews);
        $this->assertFalse($report->summary()->incomplete);
    }

    public function test_a_fresh_site_whose_events_are_older_than_the_cap_is_zero_and_incomplete(): void
    {
        config()->set('spa-analytics.stats.live_max_hours', 24);
        $this->pageView('a', '2026-03-07 10:00:00');

        $summary = Stats::between(Carbon::parse('2026-03-03'), Carbon::parse('2026-03-11'))->withLive()->summary();

        $this->assertSame(0, $summary->pageViews);
        $this->assertTrue($summary->incomplete);
    }

    public function test_a_site_without_any_events_is_zero_and_not_incomplete(): void
    {
        $summary = $this->today()->withLive()->summary();

        $this->assertSame(0, $summary->pageViews);
        $this->assertFalse($summary->incomplete);
    }

    public function test_ties_are_ordered_as_text_like_the_stored_ranking(): void
    {
        foreach ([25, 100] as $percent) {
            AnalyticsEvent::factory()->create(['type' => EventType::ScrollDepth, 'visitor_id' => 'a', 'name' => null, 'scroll_percent' => $percent, 'occurred_at' => Carbon::parse('2026-03-10 10:10:00')]);
        }

        $live = array_map(fn ($row) => $row->value, $this->today()->withLive()->top(RollupDimension::ScrollDepth));

        Carbon::setTestNow('2026-03-10 13:30:00');
        $this->rollUp();
        $stored = array_map(fn ($row) => $row->value, $this->today()->withoutLive()->top(RollupDimension::ScrollDepth));

        $this->assertSame($stored, $live);
    }

    public function test_an_empty_range_on_a_fresh_site_is_zero_and_does_not_throw(): void
    {
        $at = Carbon::parse('2026-03-10 10:00:00');

        $this->assertSame(0, Stats::between($at, $at)->withLive()->summary()->pageViews);
    }

    public function test_a_live_report_writes_nothing(): void
    {
        $this->pageView('a', '2026-03-10 09:10:00');
        $this->rollUp();
        $this->pageView('b', '2026-03-10 12:20:00');
        $before = AnalyticsRollup::query()->count();

        $report = $this->today()->withLive();
        $report->summary();
        $report->timeseries(RollupPeriod::Hour);
        $report->top(RollupDimension::Path);
        $report->goals();

        $this->assertSame($before, AnalyticsRollup::query()->count());
    }

    public function test_bots_are_left_out_of_the_live_part(): void
    {
        $this->pageView('human', '2026-03-10 12:10:00');
        $this->pageView('bot', '2026-03-10 12:11:00', bot: true);

        $this->assertSame(1, $this->today()->withLive()->summary()->pageViews);
    }

    public function test_the_preview_leaves_out_disabled_dimensions_and_stores_nothing(): void
    {
        AnalyticsEvent::factory()->create(['visitor_id' => 'a', 'occurred_at' => Carbon::parse('2026-03-10 12:10:00'), 'path' => '/pricing', 'language' => 'en']);
        $from = Carbon::parse('2026-03-10 12:00:00')->toImmutable();
        $to = Carbon::parse('2026-03-10 12:30:00')->toImmutable();

        $rows = app(RollupBuilder::class)->preview($from, $to);
        $this->assertArrayHasKey(RollupDimension::Language->key('en'), $rows);

        config()->set('spa-analytics.rollups.disabled_dimensions', ['language']);
        $rows = app(RollupBuilder::class)->preview($from, $to);

        $this->assertArrayNotHasKey(RollupDimension::Language->key('en'), $rows);
        $this->assertArrayHasKey(RollupDimension::Path->key('/pricing'), $rows);
        $this->assertSame(0, AnalyticsRollup::query()->count());
    }

    public function test_a_disabled_dimension_cannot_be_ranked_live_either(): void
    {
        config()->set('spa-analytics.rollups.disabled_dimensions', ['path']);
        $this->pageView('a', '2026-03-10 12:10:00');

        $this->expectException(\InvalidArgumentException::class);

        $this->today()->withLive()->top(RollupDimension::Path);
    }

    public function test_a_value_lifted_by_the_live_part_outranks_stored_ones_and_the_limit_applies(): void
    {
        $this->pageView('a1', '2026-03-10 09:10:00', '/a');
        $this->pageView('a2', '2026-03-10 09:20:00', '/a');
        $this->pageView('b1', '2026-03-10 09:30:00', '/b');
        $this->rollUp();
        foreach (['b2', 'b3', 'b4'] as $visitor) {
            $this->pageView($visitor, '2026-03-10 12:10:00', '/b');
        }
        $this->pageView('c1', '2026-03-10 12:15:00', '/c');

        $top = $this->today()->withLive()->top(RollupDimension::Path, 2);

        $this->assertSame(['/b', '/a'], array_map(fn ($row) => $row->value, $top));
        $this->assertSame([4, 2], array_map(fn ($row) => $row->pageViews, $top));
        $this->assertSame([4, 2], array_map(fn ($row) => $row->users, $top));
    }

    public function test_every_method_includes_the_open_hour(): void
    {
        $session = $this->openSession('a', '2026-03-10 12:05:00', ['device_type' => DeviceType::Mobile]);
        $this->pageView('a', '2026-03-10 12:05:00', '/pricing', session: $session);
        $this->pageView('a', '2026-03-10 12:08:00', '/signup', session: $session);
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'a', 'name' => 'purchase', 'value' => 9.5, 'occurred_at' => Carbon::parse('2026-03-10 12:09:00'), 'session_id' => $session->id]);
        $report = $this->today()->withLive();

        $this->assertSame(2, $report->summary()->pageViews);
        $this->assertSame(1, $report->summary()->goalCompletions);
        $this->assertSame('9.50', $report->summary()->revenue);

        $series = $report->timeseries(RollupPeriod::Hour);
        $this->assertSame(2, array_sum(array_map(fn ($point) => $point->pageViews, $series)));

        $this->assertSame(2, array_sum(array_map(fn ($point) => $point->pageViews, $report->timeseries(RollupPeriod::Day))));

        $paths = $report->top(RollupDimension::Path);
        $values = array_map(fn ($row) => $row->value, $paths);
        $this->assertContains('/pricing', $values);
        $this->assertContains('/signup', $values);
        $this->assertSame(1, $paths[0]->users);

        $devices = $report->top(RollupDimension::DeviceType);
        $this->assertSame('mobile', $devices[0]->value);

        $goals = $report->goals();
        $this->assertSame('purchase', $goals[0]->name);
        $this->assertSame(1, $goals[0]->completions);

        $funnel = $report->funnel([FunnelStep::path('/pricing'), FunnelStep::goal('purchase')]);
        $this->assertSame([1, 1], array_map(fn ($step) => $step->users, $funnel->steps));
    }

    public function test_the_live_numbers_equal_the_stored_ones_once_the_hours_are_rolled_up(): void
    {
        config()->set('spa-analytics.stats.live_max_hours', 24);
        $first = $this->openSession('a', '2026-03-10 10:05:00', ['device_type' => DeviceType::Desktop]);
        $this->pageView('a', '2026-03-10 10:05:00', '/pricing', session: $first);
        $this->pageView('a', '2026-03-10 10:40:00', '/signup', session: $first);
        $second = $this->openSession('b', '2026-03-10 11:15:00', ['device_type' => DeviceType::Mobile, 'is_new_visitor' => false]);
        $this->pageView('b', '2026-03-10 11:15:00', '/pricing', session: $second);
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'b', 'name' => 'purchase', 'value' => 20, 'occurred_at' => Carbon::parse('2026-03-10 11:20:00'), 'session_id' => $second->id]);

        $live = $this->today()->withLive();
        $liveSummary = $live->summary()->toArray();
        $liveTop = array_map(fn ($row) => $row->toArray(), $live->top(RollupDimension::Path));
        $liveGoals = array_map(fn ($row) => $row->toArray(), $live->goals());

        Carbon::setTestNow('2026-03-10 13:30:00');
        $this->rollUp();
        $stored = $this->today()->withoutLive();
        $storedSummary = $stored->summary()->toArray();

        unset($liveSummary['through'], $storedSummary['through']);
        $this->assertEquals($storedSummary, $liveSummary);
        $this->assertEquals(array_map(fn ($row) => $row->toArray(), $stored->top(RollupDimension::Path)), $liveTop);
        $this->assertEquals(array_map(fn ($row) => $row->toArray(), $stored->goals()), $liveGoals);
    }

    public function test_the_live_series_and_funnel_equal_the_stored_ones_once_the_hours_are_rolled_up(): void
    {
        config()->set('spa-analytics.stats.live_max_hours', 24);
        $this->pageView('a', '2026-03-10 10:05:00', '/pricing');
        $this->pageView('a', '2026-03-10 10:40:00', '/signup');
        $this->pageView('b', '2026-03-10 11:15:00', '/pricing');
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'a', 'name' => 'purchase', 'occurred_at' => Carbon::parse('2026-03-10 11:20:00')]);
        $steps = [FunnelStep::path('/pricing'), FunnelStep::goal('purchase')];

        $live = $this->today()->withLive();
        $liveSeries = array_map(fn ($point) => $point->toArray(), $live->timeseries(RollupPeriod::Hour));
        $liveFunnel = array_map(fn ($step) => $step->users, $live->funnel($steps)->steps);

        Carbon::setTestNow('2026-03-10 13:30:00');
        $this->rollUp();
        $stored = $this->today()->withoutLive();
        $storedSeries = array_slice(array_map(fn ($point) => $point->toArray(), $stored->timeseries(RollupPeriod::Hour)), 0, count($liveSeries));

        $this->assertEquals($storedSeries, $liveSeries);
        $this->assertSame(array_map(fn ($step) => $step->users, $stored->funnel($steps)->steps), $liveFunnel);
    }

    public function test_live_path_scroll_rows_have_exact_users_and_follow_the_stored_ones(): void
    {
        foreach ([['a', 50], ['a', 50], ['b', 50], ['b', 25]] as [$visitor, $percent]) {
            AnalyticsEvent::factory()->create(['type' => EventType::ScrollDepth, 'visitor_id' => $visitor, 'name' => null, 'path' => '/pricing', 'scroll_percent' => $percent, 'occurred_at' => Carbon::parse('2026-03-10 10:10:00')]);
        }

        $live = array_map(fn ($row) => [$row->value, $row->events, $row->users], $this->today()->withLive()->top(RollupDimension::PathScroll));

        Carbon::setTestNow('2026-03-10 13:30:00');
        $this->rollUp();
        $stored = array_map(fn ($row) => [$row->value, $row->events, $row->users], $this->today()->withoutLive()->top(RollupDimension::PathScroll));

        $this->assertSame([['50 /pricing', 3, 2], ['25 /pricing', 1, 1]], $live);
        $this->assertSame($stored, $live);
    }

    public function test_scroll_depth_of_a_path_with_a_space_keeps_its_exact_users_and_topfor_finds_it(): void
    {
        foreach (['a', 'b'] as $visitor) {
            AnalyticsEvent::factory()->create(['type' => EventType::ScrollDepth, 'visitor_id' => $visitor, 'name' => null, 'path' => '/a b', 'scroll_percent' => 75, 'occurred_at' => Carbon::parse('2026-03-10 10:10:00')]);
        }
        AnalyticsEvent::factory()->create(['type' => EventType::ScrollDepth, 'visitor_id' => 'a', 'name' => null, 'path' => '/A b', 'scroll_percent' => 75, 'occurred_at' => Carbon::parse('2026-03-10 10:11:00')]);
        Carbon::setTestNow('2026-03-10 13:30:00');
        $this->rollUp();

        $report = $this->today()->withoutLive();
        $rows = collect($report->top(RollupDimension::PathScroll))->mapWithKeys(fn ($row) => [$row->value => $row->users])->all();
        $found = $report->topFor(RollupDimension::PathScroll, ['75 /a b', '75 /nothing']);

        $this->assertSame(['75 /a b' => 2, '75 /A b' => 1], $rows);
        $this->assertSame(['75 /a b'], array_keys($found));
        $this->assertSame(2, $found['75 /a b']->users);
    }

    public function test_live_is_on_by_default_and_the_config_turns_it_off(): void
    {
        $this->pageView('a', '2026-03-10 12:10:00');

        $this->assertSame(1, $this->today()->summary()->pageViews);

        config()->set('spa-analytics.stats.live', false);

        $this->assertSame(0, $this->today()->summary()->pageViews);
        $this->assertSame(1, $this->today()->withLive()->summary()->pageViews);
    }

    public function test_last_days_still_leaves_today_out(): void
    {
        $this->pageView('a', '2026-03-10 12:10:00');
        $this->pageView('b', '2026-03-09 12:10:00');
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-09 00:00:00')->toImmutable());

        $this->assertSame(1, Stats::lastDays(1)->summary()->pageViews);
    }
}
