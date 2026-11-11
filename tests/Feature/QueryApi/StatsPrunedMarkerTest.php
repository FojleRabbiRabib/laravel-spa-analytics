<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\FunnelStep;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsState;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * A late row on a day the prune already cleared must not make the query layer count that day from raw rows.
 */
class StatsPrunedMarkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function visit(string $visitor, string $time, bool $new = true): void
    {
        $session = AnalyticsSession::factory()->create([
            'visitor_id' => $visitor,
            'entry_path' => '/a',
            'exit_path' => '/a',
            'started_at' => Carbon::parse($time),
            'last_seen_at' => Carbon::parse($time),
            'page_views' => 1,
            'is_new_visitor' => $new,
        ]);

        AnalyticsEvent::factory()->create([
            'visitor_id' => $visitor,
            'session_id' => $session->id,
            'path' => '/a',
            'occurred_at' => Carbon::parse($time),
        ]);
    }

    /**
     * Two days rolled up, the first one pruned and marked, then a late page view lands on the pruned day.
     * With $extras, an error page view by v1 and a goal by v1 exist before the rollup, and a late goal by v3 comes after.
     */
    private function prunedDayWithALateRow(bool $extras = false): void
    {
        $this->visit('v1', '2026-03-08 10:00:00');
        $this->visit('v2', '2026-03-08 11:00:00');
        $this->visit('v1', '2026-03-09 10:00:00', false);

        if ($extras) {
            AnalyticsEvent::factory()->create(['visitor_id' => 'v1', 'path' => '/missing', 'status' => 404, 'occurred_at' => Carbon::parse('2026-03-08 10:05:00')]);
            AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'v1', 'name' => 'buy', 'occurred_at' => Carbon::parse('2026-03-08 10:30:00')]);
        }

        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08 00:00:00')->toImmutable());
        AnalyticsEvent::query()->where('occurred_at', '<', Carbon::parse('2026-03-09'))->delete();
        AnalyticsSession::query()->where('started_at', '<', Carbon::parse('2026-03-09'))->delete();
        AnalyticsState::factory()->prunedThrough('2026-03-09 00:00:00')->create();

        AnalyticsEvent::factory()->create(['visitor_id' => 'v3', 'path' => '/a', 'occurred_at' => Carbon::parse('2026-03-08 12:00:00')]);

        if ($extras) {
            AnalyticsEvent::factory()->create(['visitor_id' => 'v3', 'path' => '/missing', 'status' => 404, 'occurred_at' => Carbon::parse('2026-03-08 12:05:00')]);
            AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'v3', 'name' => 'buy', 'occurred_at' => Carbon::parse('2026-03-08 12:30:00')]);
        }
    }

    public function test_a_late_row_on_a_pruned_day_does_not_make_the_users_exact(): void
    {
        $this->prunedDayWithALateRow();

        $summary = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'))->summary();

        $this->assertSame(3, $summary->users);
        $this->assertFalse($summary->usersExact);
    }

    public function test_a_late_row_on_a_pruned_day_does_not_make_a_row_exact(): void
    {
        $this->prunedDayWithALateRow();

        $rows = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'))->top(RollupDimension::Path);

        $this->assertSame(3, $rows[0]->users);
        $this->assertFalse($rows[0]->usersExact);
    }

    public function test_a_late_row_on_a_pruned_day_does_not_make_a_funnel_complete(): void
    {
        $this->prunedDayWithALateRow();

        $funnel = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'))
            ->funnel([FunnelStep::path('/a'), FunnelStep::path('/b')]);

        $this->assertFalse($funnel->complete);
        $this->assertSame('2026-03-09 00:00:00', $funnel->coveredFrom?->toDateTimeString());
    }

    public function test_a_late_goal_on_a_pruned_day_does_not_make_the_goal_users_exact(): void
    {
        $this->prunedDayWithALateRow(extras: true);

        $goal = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'))->goals()[0];

        $this->assertSame('buy', $goal->name);
        $this->assertSame(1, $goal->users);
        $this->assertFalse($goal->usersExact);
    }

    public function test_a_late_error_page_view_on_a_pruned_day_does_not_make_the_error_path_row_exact(): void
    {
        $this->prunedDayWithALateRow(extras: true);

        $row = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'))->top(RollupDimension::ErrorPath)[0];

        $this->assertSame('404 /missing', $row->value);
        $this->assertSame(1, $row->users);
        $this->assertFalse($row->usersExact);
    }

    public function test_exit_path_rows_are_inexact_when_the_range_covers_pruned_days(): void
    {
        $this->prunedDayWithALateRow();

        $rows = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'))->top(RollupDimension::ExitPath);

        $this->assertNotEmpty($rows);
        $this->assertFalse($rows[0]->usersExact);
    }

    public function test_a_range_entirely_before_the_marker_has_inexact_users_from_the_rollups(): void
    {
        $this->prunedDayWithALateRow();

        $summary = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-09'))->summary();

        $this->assertSame(2, $summary->users);
        $this->assertFalse($summary->usersExact);
    }

    public function test_the_comparison_marks_the_previous_period_inexact_when_it_lies_before_the_marker(): void
    {
        $this->prunedDayWithALateRow();

        $comparison = Stats::between(Carbon::parse('2026-03-09'), Carbon::parse('2026-03-10'))->compareToPrevious()->summary();

        $this->assertFalse($comparison->previous->usersExact);
        $this->assertSame(2, $comparison->previous->users);
    }

    public function test_without_a_marker_the_oldest_raw_row_still_decides(): void
    {
        $this->prunedDayWithALateRow();
        AnalyticsState::query()->delete();

        $summary = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'))->summary();

        $this->assertTrue($summary->usersExact);
    }
}
