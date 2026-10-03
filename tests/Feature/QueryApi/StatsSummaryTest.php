<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function at(string $moment): Carbon
    {
        return Carbon::parse($moment);
    }

    /**
     * A session with its page views, so rollups and raw queries agree.
     *
     * @param  array<int, string>  $times
     * @param  array<string, mixed>  $attributes
     */
    private function visit(string $visitor, array $times, array $attributes = []): AnalyticsSession
    {
        sort($times);

        $session = AnalyticsSession::factory()->create([
            'visitor_id' => $visitor,
            'started_at' => $this->at($times[0]),
            'last_seen_at' => $this->at(end($times)),
            'page_views' => count($times),
            'is_new_visitor' => true,
            ...$attributes,
        ]);

        foreach ($times as $time) {
            AnalyticsEvent::factory()->create([
                'visitor_id' => $visitor,
                'session_id' => $session->id,
                'occurred_at' => $this->at($time),
                'is_bot' => $attributes['is_bot'] ?? false,
            ]);
        }

        return $session;
    }

    private function goal(string $visitor, string $time, string $name = 'purchase', ?float $value = null, bool $bot = false): void
    {
        AnalyticsEvent::factory()->goal()->create([
            'visitor_id' => $visitor,
            'name' => $name,
            'value' => $value,
            'occurred_at' => $this->at($time),
            'is_bot' => $bot,
        ]);
    }

    private function rollUp(string $since = '2026-03-08 00:00:00'): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse($since)->toImmutable());
    }

    public function test_the_summary_adds_up_the_rollups_over_whole_days(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00', '2026-03-08 10:10:00', '2026-03-08 10:20:00']);
        $this->visit('v2', ['2026-03-09 09:00:00']);
        $this->visit('v3', ['2026-03-09 15:00:00', '2026-03-09 15:30:00']);
        $this->goal('v1', '2026-03-08 10:15:00', 'purchase', 40);
        $this->goal('v3', '2026-03-09 15:20:00', 'purchase', 9.5);
        AnalyticsEvent::factory()->custom()->create(['visitor_id' => 'v2', 'name' => 'clicked', 'occurred_at' => $this->at('2026-03-09 09:05:00')]);
        $this->rollUp();

        $summary = Stats::between($this->at('2026-03-08'), $this->at('2026-03-10'))->summary();

        $this->assertSame(6, $summary->pageViews);
        $this->assertSame(3, $summary->sessions);
        $this->assertSame(1, $summary->bounces);
        $this->assertEqualsWithDelta(1 / 3, $summary->bounceRate, 0.0001);
        $this->assertSame(1000.0, $summary->avgSessionDuration);
        $this->assertSame(3, $summary->events);
        $this->assertSame(2, $summary->goalCompletions);
        $this->assertSame('49.50', $summary->revenue);
        $this->assertFalse($summary->incomplete);
    }

    public function test_users_are_distinct_people_over_the_range_not_a_sum_of_days(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00']);
        $this->visit('v1', ['2026-03-09 10:00:00'], ['is_new_visitor' => false]);
        $this->visit('v2', ['2026-03-09 11:00:00']);
        $this->rollUp();

        $summary = Stats::between($this->at('2026-03-08'), $this->at('2026-03-10'))->summary();

        $this->assertSame(3, $summary->pageViews);
        $this->assertSame(2, $summary->users);
        $this->assertTrue($summary->usersExact);
    }

    public function test_new_and_returning_users(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00'], ['is_new_visitor' => false]);
        $this->visit('v2', ['2026-03-08 11:00:00'], ['is_new_visitor' => true]);
        $this->visit('v3', ['2026-03-09 11:00:00'], ['is_new_visitor' => true]);
        $this->rollUp();

        $summary = Stats::between($this->at('2026-03-08'), $this->at('2026-03-10'))->summary();

        $this->assertSame(3, $summary->users);
        $this->assertSame(2, $summary->newUsers);
        $this->assertSame(1, $summary->returningUsers);
    }

    public function test_bots_are_left_out(): void
    {
        $this->visit('human', ['2026-03-08 10:00:00']);
        $this->visit('bot', ['2026-03-08 11:00:00'], ['is_bot' => true]);
        $this->goal('bot', '2026-03-08 11:05:00', 'purchase', 100, true);
        $this->rollUp();

        $summary = Stats::between($this->at('2026-03-08'), $this->at('2026-03-09'))->summary();

        $this->assertSame(1, $summary->users);
        $this->assertSame(1, $summary->pageViews);
        $this->assertSame(0, $summary->goalCompletions);
    }

    public function test_the_range_stops_at_the_last_rolled_up_hour_for_counts_and_users_alike(): void
    {
        Carbon::setTestNow('2026-03-10 11:30:00');
        $this->visit('v1', ['2026-03-10 09:10:00']);
        $this->rollUp('2026-03-10 00:00:00');
        Carbon::setTestNow('2026-03-10 12:30:00');
        $this->visit('late', ['2026-03-10 12:10:00']);

        $summary = Stats::between($this->at('2026-03-10 00:00:00'), $this->at('2026-03-11 00:00:00'))->summary();

        $this->assertSame(1, $summary->pageViews);
        $this->assertSame(1, $summary->users);
        $this->assertSame('2026-03-10 11:00:00', $summary->through?->toDateTimeString());
    }

    public function test_the_hour_still_running_is_not_counted_for_users_either(): void
    {
        Carbon::setTestNow('2026-03-10 11:30:00');
        $this->visit('v1', ['2026-03-10 09:10:00']);
        $this->rollUp('2026-03-10 00:00:00');
        Carbon::setTestNow('2026-03-10 11:50:00');
        $this->visit('late', ['2026-03-10 11:45:00']);

        $summary = Stats::between($this->at('2026-03-10 00:00:00'), $this->at('2026-03-11 00:00:00'))->summary();

        $this->assertSame(1, $summary->pageViews);
        $this->assertSame(1, $summary->users);
        $this->assertSame('2026-03-10 11:00:00', $summary->through?->toDateTimeString());
    }

    public function test_days_whose_raw_rows_were_pruned_fall_back_to_summed_daily_users(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00']);
        $this->visit('v2', ['2026-03-08 11:00:00']);
        $this->visit('v1', ['2026-03-09 10:00:00'], ['is_new_visitor' => false]);
        $this->rollUp();
        AnalyticsEvent::query()->where('occurred_at', '<', $this->at('2026-03-09'))->delete();
        AnalyticsSession::query()->where('started_at', '<', $this->at('2026-03-09'))->delete();

        $summary = Stats::between($this->at('2026-03-08'), $this->at('2026-03-10'))->summary();

        $this->assertSame(3, $summary->pageViews);
        $this->assertSame(3, $summary->users);
        $this->assertFalse($summary->usersExact);
    }

    public function test_an_empty_stretch_before_the_first_raw_row_does_not_make_users_inexact(): void
    {
        $this->visit('v1', ['2026-03-09 10:00:00']);
        $this->rollUp('2026-03-01 00:00:00');

        $summary = Stats::between($this->at('2026-03-01'), $this->at('2026-03-10'))->summary();

        $this->assertSame(1, $summary->users);
        $this->assertTrue($summary->usersExact);
    }

    public function test_conversion_rate_counts_goal_users_who_are_also_users(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00']);
        $this->visit('v2', ['2026-03-08 11:00:00']);
        $this->visit('v3', ['2026-03-08 12:00:00']);
        $this->visit('v4', ['2026-03-08 13:00:00']);
        $this->goal('v1', '2026-03-08 10:05:00');
        $this->goal('v1', '2026-03-08 10:06:00');
        $this->goal('v2', '2026-03-08 11:05:00');
        $this->rollUp();

        $summary = Stats::between($this->at('2026-03-08'), $this->at('2026-03-09'))->summary();

        $this->assertSame(3, $summary->goalCompletions);
        $this->assertSame(0.5, $summary->conversionRate);
    }

    public function test_a_goal_without_a_page_view_never_pushes_conversion_above_one(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00']);
        $this->goal('v1', '2026-03-08 10:05:00');
        $this->goal('webhook-only', '2026-03-08 11:00:00');
        $this->goal('another', '2026-03-08 12:00:00');
        $this->rollUp();

        $summary = Stats::between($this->at('2026-03-08'), $this->at('2026-03-09'))->summary();

        $this->assertSame(1, $summary->users);
        $this->assertSame(3, $summary->goalCompletions);
        $this->assertSame(1.0, $summary->conversionRate);
    }

    public function test_rates_are_zero_when_there_is_nothing_to_divide_by(): void
    {
        $this->rollUp();

        $summary = Stats::between($this->at('2026-03-08'), $this->at('2026-03-09'))->summary();

        $this->assertSame(0, $summary->users);
        $this->assertSame(0.0, $summary->bounceRate);
        $this->assertSame(0.0, $summary->avgSessionDuration);
        $this->assertSame(0.0, $summary->conversionRate);
    }

    public function test_a_range_with_no_rollups_at_all_is_empty(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00']);

        $summary = Stats::between($this->at('2026-03-08'), $this->at('2026-03-09'))->summary();

        $this->assertSame(0, $summary->pageViews);
        $this->assertSame(0, $summary->users);
        $this->assertNull($summary->through);
    }

    public function test_missing_hour_rollups_at_the_edges_mark_the_result_incomplete(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00']);
        $this->rollUp();
        AnalyticsRollup::query()->where('period', RollupPeriod::Hour)->where('bucket_start', '2026-03-08 10:00:00')->delete();

        $summary = Stats::between($this->at('2026-03-08 10:00:00'), $this->at('2026-03-08 12:00:00'))->summary();

        $this->assertTrue($summary->incomplete);
    }

    public function test_a_day_without_a_day_row_is_read_from_its_hour_rows(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00']);
        $this->visit('v2', ['2026-03-08 14:00:00']);
        $this->rollUp();
        AnalyticsRollup::query()->where('period', RollupPeriod::Day)->delete();

        $summary = Stats::between($this->at('2026-03-08'), $this->at('2026-03-09'))->summary();

        $this->assertSame(2, $summary->pageViews);
        $this->assertFalse($summary->incomplete);
    }

    public function test_last_days_covers_the_complete_days_before_today(): void
    {
        $this->visit('v1', ['2026-03-09 10:00:00']);
        $this->visit('today', ['2026-03-10 09:00:00']);
        $this->rollUp('2026-03-01 00:00:00');

        $summary = Stats::lastDays(2)->summary();

        $this->assertSame(1, $summary->pageViews);
        $this->assertSame(1, $summary->users);
    }

    public function test_the_summary_serialises_to_json_friendly_values(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00']);
        $this->rollUp();

        $array = Stats::between($this->at('2026-03-08'), $this->at('2026-03-09'))->summary()->toArray();

        $this->assertSame(1, $array['pageViews']);
        $this->assertNotFalse(json_encode($array));
    }
}
