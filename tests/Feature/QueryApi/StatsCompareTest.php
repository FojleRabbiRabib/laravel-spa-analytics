<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\Change;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\FunnelStep;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Query\StatsComparison;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsCompareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function pageView(string $visitor, string $at, string $path = '/pricing', int $status = 200): void
    {
        AnalyticsEvent::factory()->create(['visitor_id' => $visitor, 'occurred_at' => Carbon::parse($at), 'path' => $path, 'status' => $status]);
    }

    private function purchase(string $visitor, string $at, float $value): void
    {
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => $visitor, 'name' => 'purchase', 'value' => $value, 'occurred_at' => Carbon::parse($at)]);
    }

    private function rollUp(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-06 00:00:00')->toImmutable());
    }

    /**
     * 8 March: two page views. 9 March: four page views, three people on /pricing, one purchase of 10.
     */
    private function twoDays(): void
    {
        $this->pageView('e', '2026-03-08 10:00:00', '/pricing', 404);
        $this->pageView('f', '2026-03-08 11:00:00', '/docs');
        $this->pageView('a', '2026-03-09 09:00:00', '/pricing');
        $this->pageView('b', '2026-03-09 10:00:00', '/pricing');
        $this->pageView('c', '2026-03-09 11:00:00', '/pricing', 404);
        $this->pageView('d', '2026-03-09 12:00:00', '/home');
        $this->purchase('a', '2026-03-09 09:05:00', 10);
        $this->rollUp();
    }

    private function yesterday(): StatsComparison
    {
        return Stats::between(Carbon::parse('2026-03-09'), Carbon::parse('2026-03-10'))->compareToPrevious();
    }

    public function test_the_summary_shows_both_periods_and_the_change(): void
    {
        $this->twoDays();

        $comparison = $this->yesterday()->summary();

        $this->assertSame(4, $comparison->current->pageViews);
        $this->assertSame(2, $comparison->previous->pageViews);
        $this->assertEqualsWithDelta(2.0, $comparison->changes['pageViews']->absolute, 0.0001);
        $this->assertEqualsWithDelta(100.0, $comparison->changes['pageViews']->percent, 0.0001);
        $this->assertEqualsWithDelta(2.0, $comparison->changes['users']->absolute, 0.0001);
    }

    public function test_the_percent_is_null_when_the_previous_value_is_zero_and_money_is_exact(): void
    {
        $this->twoDays();

        $changes = $this->yesterday()->summary()->changes;

        $this->assertEqualsWithDelta(1.0, $changes['goalCompletions']->absolute, 0.0001);
        $this->assertNull($changes['goalCompletions']->percent);
        $this->assertEqualsWithDelta(10.0, $changes['revenue']->absolute, 0.0001);
        $this->assertNull($changes['revenue']->percent);
    }

    public function test_an_empty_previous_period_compares_without_errors(): void
    {
        $this->pageView('a', '2026-03-09 09:00:00');
        $this->rollUp();

        $comparison = $this->yesterday()->summary();

        $this->assertSame(0, $comparison->previous->pageViews);
        $this->assertNull($comparison->changes['pageViews']->percent);
        $this->assertEqualsWithDelta(1.0, $comparison->changes['pageViews']->absolute, 0.0001);
    }

    public function test_rates_change_by_the_difference_of_the_ratios(): void
    {
        $this->twoDays();

        $changes = $this->yesterday()->summary()->changes;

        $this->assertEqualsWithDelta(0.25, $changes['conversionRate']->absolute, 0.0001);
    }

    public function test_top_rows_are_compared_by_value_and_a_new_value_has_no_previous_row(): void
    {
        $this->twoDays();

        $rows = $this->yesterday()->top(RollupDimension::Path);
        $byValue = [];

        foreach ($rows as $row) {
            $byValue[$row->current->value] = $row;
        }

        $this->assertSame(3, $byValue['/pricing']->current->pageViews);
        $this->assertSame(1, $byValue['/pricing']->previous?->pageViews);
        $this->assertEqualsWithDelta(200.0, $byValue['/pricing']->changes['pageViews']->percent, 0.0001);
        $this->assertNull($byValue['/home']->previous);
        $this->assertNull($byValue['/home']->changes['pageViews']->percent);
        $this->assertArrayNotHasKey('/docs', $byValue);
    }

    public function test_numeric_string_values_such_as_a_status_are_matched_across_periods(): void
    {
        $this->twoDays();

        $rows = $this->yesterday()->top(RollupDimension::Status);
        $byValue = [];

        foreach ($rows as $row) {
            $byValue[$row->current->value] = $row;
        }

        $this->assertSame(1, $byValue['404']->current->pageViews);
        $this->assertSame(1, $byValue['404']->previous?->pageViews);
    }

    public function test_goals_are_compared_and_a_goal_without_previous_completions_has_no_previous_row(): void
    {
        $this->twoDays();

        $goals = $this->yesterday()->goals();

        $this->assertCount(1, $goals);
        $this->assertSame('purchase', $goals[0]->current->name);
        $this->assertNull($goals[0]->previous);
        $this->assertEqualsWithDelta(1.0, $goals[0]->changes['completions']->absolute, 0.0001);
    }

    public function test_scroll_depth_by_path_is_compared_by_value_with_exact_users(): void
    {
        foreach ([['a', '2026-03-09 09:00:00'], ['b', '2026-03-09 10:00:00'], ['a', '2026-03-08 09:00:00']] as [$visitor, $at]) {
            AnalyticsEvent::factory()->create(['type' => EventType::ScrollDepth, 'visitor_id' => $visitor, 'name' => null, 'path' => '/pricing', 'scroll_percent' => 50, 'occurred_at' => Carbon::parse($at)]);
        }
        $this->rollUp();

        $rows = $this->yesterday()->top(RollupDimension::PathScroll);

        $this->assertSame('50 /pricing', $rows[0]->current->value);
        $this->assertSame(2, $rows[0]->current->users);
        $this->assertSame(1, $rows[0]->previous?->users);
        $this->assertEqualsWithDelta(100.0, $rows[0]->changes['users']->percent, 0.0001);
    }

    public function test_the_series_pairs_each_point_with_the_one_at_the_same_place(): void
    {
        $this->twoDays();

        $hours = $this->yesterday()->timeseries(RollupPeriod::Hour);

        $this->assertCount(24, $hours);
        $this->assertSame('2026-03-09 00:00:00', $hours[0]->current->start->toDateTimeString());
        $this->assertSame('2026-03-08 00:00:00', $hours[0]->previous?->start->toDateTimeString());
        $this->assertSame(1, $hours[10]->current->pageViews);
        $this->assertSame(1, $hours[10]->previous?->pageViews);
        $this->assertEqualsWithDelta(0.0, $hours[10]->changes['pageViews']->absolute, 0.0001);
    }

    public function test_the_funnel_is_compared_per_step_and_overall(): void
    {
        $this->twoDays();

        $comparison = $this->yesterday()->funnel([FunnelStep::path('/pricing'), FunnelStep::goal('purchase')]);

        $this->assertSame([3, 1], array_map(fn ($step) => $step->users, $comparison->current->steps));
        $this->assertSame([1, 0], array_map(fn ($step) => $step->users, $comparison->previous->steps));
        $this->assertEqualsWithDelta(2.0, $comparison->stepChanges[0]->absolute, 0.0001);
        $this->assertEqualsWithDelta(1.0, $comparison->stepChanges[1]->absolute, 0.0001);
        $this->assertEqualsWithDelta(1 / 3, $comparison->conversionRate->absolute, 0.0001);
    }

    public function test_any_other_range_can_be_the_comparison(): void
    {
        $this->twoDays();

        $comparison = Stats::between(Carbon::parse('2026-03-09'), Carbon::parse('2026-03-10'))->compareTo(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-09'))->summary();

        $this->assertSame(2, $comparison->previous->pageViews);
    }

    public function test_a_reversed_or_empty_range_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Stats::between(Carbon::parse('2026-03-09'), Carbon::parse('2026-03-10'))->compareTo(Carbon::parse('2026-03-09'), Carbon::parse('2026-03-09'));
    }

    public function test_a_period_that_is_still_running_is_compared_with_the_same_time_of_day_before(): void
    {
        $this->pageView('old', '2026-03-09 09:00:00');
        $this->pageView('afternoon', '2026-03-09 14:00:00');
        $this->pageView('today', '2026-03-10 09:10:00');
        $this->rollUp();
        $this->pageView('live', '2026-03-10 12:10:00');

        $comparison = Stats::between(Carbon::parse('2026-03-10 00:00:00'), Carbon::parse('2026-03-11 00:00:00'))->withLive()->compareToPrevious()->summary();

        $this->assertSame(2, $comparison->current->pageViews);
        $this->assertSame(1, $comparison->previous->pageViews);
    }

    public function test_the_previous_period_ends_exactly_where_the_current_one_has_read_to(): void
    {
        $this->pageView('before', '2026-03-09 12:15:00');
        $this->pageView('after', '2026-03-09 12:45:00');
        $this->pageView('today', '2026-03-10 12:10:00');
        $this->rollUp();

        $comparison = Stats::between(Carbon::parse('2026-03-10 00:00:00'), Carbon::parse('2026-03-11 00:00:00'))->withLive()->compareToPrevious()->summary();

        $this->assertSame(1, $comparison->current->pageViews);
        $this->assertSame(1, $comparison->previous->pageViews);
        $this->assertSame('2026-03-09 12:30:00', $comparison->previous->through?->toDateTimeString());
    }

    public function test_without_live_the_previous_period_stops_where_the_stored_numbers_stop(): void
    {
        $this->pageView('before', '2026-03-09 11:45:00');
        $this->pageView('inside', '2026-03-09 12:15:00');
        $this->pageView('today', '2026-03-10 09:10:00');
        $this->rollUp();

        $comparison = Stats::between(Carbon::parse('2026-03-10 00:00:00'), Carbon::parse('2026-03-11 00:00:00'))->withoutLive()->compareToPrevious()->summary();

        $this->assertSame('2026-03-10 12:00:00', $comparison->current->through?->toDateTimeString());
        $this->assertSame(1, $comparison->previous->pageViews);
    }

    public function test_the_previous_period_follows_wall_clock_days_across_a_daylight_saving_change(): void
    {
        config()->set('app.timezone', 'America/New_York');
        Carbon::setTestNow(Carbon::parse('2026-03-12 12:30:00', 'America/New_York'));
        $this->pageView('extra', '2026-03-07 23:30:00');
        $this->pageView('spring', '2026-03-08 00:30:00');
        $this->pageView('after', '2026-03-09 00:30:00');
        $this->rollUp();

        $comparison = Stats::between(Carbon::parse('2026-03-09 00:00:00', 'America/New_York'), Carbon::parse('2026-03-10 00:00:00', 'America/New_York'))->compareToPrevious()->summary();

        $this->assertSame(1, $comparison->previous->pageViews);
    }

    public function test_a_range_that_contains_the_spring_forward_day_still_moves_back_by_calendar_days(): void
    {
        config()->set('app.timezone', 'America/New_York');
        Carbon::setTestNow(Carbon::parse('2026-03-12 12:30:00', 'America/New_York'));
        $this->pageView('midnight', '2026-03-07 00:30:00');
        $this->pageView('evening', '2026-03-07 23:30:00');
        $this->pageView('spring', '2026-03-08 00:30:00');
        $this->rollUp();

        $day = Stats::between(Carbon::parse('2026-03-08 00:00:00', 'America/New_York'), Carbon::parse('2026-03-09 00:00:00', 'America/New_York'))->compareToPrevious()->summary();
        $week = Stats::between(Carbon::parse('2026-03-08 00:00:00', 'America/New_York'), Carbon::parse('2026-03-15 00:00:00', 'America/New_York'))->compareToPrevious()->summary();

        $this->assertSame(2, $day->previous->pageViews);
        $this->assertSame(0, $week->previous->pageViews);
        $this->assertSame(1, $week->current->pageViews);
    }

    public function test_the_last_partial_hour_of_the_previous_series_is_not_counted_twice(): void
    {
        $this->pageView('a', '2026-03-09 12:10:00');
        $this->pageView('b', '2026-03-09 12:45:00');
        $this->rollUp();

        $points = Stats::between(Carbon::parse('2026-03-10 00:00:00'), Carbon::parse('2026-03-11 00:00:00'))->withLive()->compareToPrevious()->timeseries(RollupPeriod::Hour);
        $days = Stats::between(Carbon::parse('2026-03-10 00:00:00'), Carbon::parse('2026-03-11 00:00:00'))->withLive()->compareToPrevious()->timeseries(RollupPeriod::Day);

        $this->assertSame(1, $points[12]->previous?->pageViews);
        $this->assertSame(1, $days[0]->previous?->pageViews);
    }

    public function test_a_range_in_the_future_is_compared_with_the_whole_previous_period(): void
    {
        $this->pageView('morning', '2026-03-10 09:00:00');
        $this->pageView('evening', '2026-03-10 20:00:00');
        $this->rollUp();

        $comparison = Stats::between(Carbon::parse('2026-03-11 00:00:00'), Carbon::parse('2026-03-12 00:00:00'))->compareToPrevious()->summary();

        $this->assertSame(0, $comparison->current->pageViews);
        $this->assertSame(1, $comparison->previous->pageViews);
    }

    public function test_series_of_different_lengths_leave_the_extra_points_without_a_counterpart(): void
    {
        $this->pageView('a', '2026-03-08 09:00:00');
        $this->pageView('b', '2026-03-09 09:00:00');
        $this->rollUp();

        $points = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'))->compareTo(Carbon::parse('2026-03-07'), Carbon::parse('2026-03-08'))->timeseries(RollupPeriod::Day);

        $this->assertCount(2, $points);
        $this->assertNotNull($points[0]->previous);
        $this->assertNull($points[1]->previous);
        $this->assertNull($points[1]->changes['pageViews']->percent);
        $this->assertEqualsWithDelta(1.0, $points[1]->changes['pageViews']->absolute, 0.0001);
    }

    public function test_a_goal_with_a_numeric_name_is_matched_across_periods(): void
    {
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'a', 'name' => '404', 'occurred_at' => Carbon::parse('2026-03-09 09:00:00')]);
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'a', 'name' => '404', 'occurred_at' => Carbon::parse('2026-03-08 09:00:00')]);
        $this->pageView('a', '2026-03-09 08:00:00');
        $this->rollUp();

        $goals = $this->yesterday()->goals();

        $this->assertSame(1, $goals[0]->previous?->completions);
    }

    public function test_float_changes_are_free_of_floating_point_noise(): void
    {
        $change = Change::between(0.3, 0.1);

        $this->assertSame(0.2, $change->absolute);
    }

    public function test_a_period_that_is_over_is_compared_with_the_whole_previous_period(): void
    {
        $this->pageView('morning', '2026-03-09 09:00:00');
        $this->pageView('afternoon', '2026-03-09 14:00:00');
        $this->pageView('today', '2026-03-10 09:10:00');
        $this->rollUp();

        $comparison = Stats::between(Carbon::parse('2026-03-10 00:00:00'), Carbon::parse('2026-03-10 12:00:00'))->compareToPrevious()->summary();

        $this->assertSame(1, $comparison->previous->pageViews);

        $whole = Stats::between(Carbon::parse('2026-03-09 00:00:00'), Carbon::parse('2026-03-10 00:00:00'))->compareToPrevious()->summary();

        $this->assertSame(0, $whole->previous->pageViews);
    }

    public function test_the_comparison_follows_the_live_setting_of_its_report(): void
    {
        $this->pageView('live', '2026-03-10 12:10:00');
        $this->pageView('yesterday', '2026-03-09 12:10:00');
        $range = [Carbon::parse('2026-03-10 00:00:00'), Carbon::parse('2026-03-11 00:00:00')];

        $this->assertSame(1, Stats::between(...$range)->withLive()->compareToPrevious()->summary()->current->pageViews);
        $this->assertSame(0, Stats::between(...$range)->withoutLive()->compareToPrevious()->summary()->current->pageViews);
    }

    public function test_the_comparison_serialises_to_json_friendly_arrays(): void
    {
        $this->twoDays();
        $comparison = $this->yesterday();

        $this->assertIsString(json_encode($comparison->summary()->toArray(), JSON_THROW_ON_ERROR));
        $this->assertIsString(json_encode(array_map(fn ($row) => $row->toArray(), $comparison->top(RollupDimension::Path)), JSON_THROW_ON_ERROR));
        $this->assertIsString(json_encode($comparison->funnel([FunnelStep::path('/pricing'), FunnelStep::goal('purchase')])->toArray(), JSON_THROW_ON_ERROR));
        $this->assertArrayHasKey('changes', $comparison->summary()->toArray());
    }
}
