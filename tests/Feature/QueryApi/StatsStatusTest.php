<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function pageView(string $visitor, string $path, ?int $status, string $time = '2026-03-08 10:00:00'): void
    {
        AnalyticsEvent::factory()->create([
            'visitor_id' => $visitor,
            'path' => $path,
            'status' => $status,
            'occurred_at' => Carbon::parse($time),
        ]);
    }

    private function rollUp(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08 00:00:00')->toImmutable());
    }

    private function report()
    {
        return Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'));
    }

    public function test_statuses_are_ranked_by_page_views_with_exact_users(): void
    {
        $this->pageView('v1', '/a', 200);
        $this->pageView('v1', '/b', 200, '2026-03-09 10:00:00');
        $this->pageView('v2', '/a', 200);
        $this->pageView('v3', '/gone', 404);
        $this->pageView('v3', '/gone', 404, '2026-03-09 11:00:00');
        $this->pageView('v4', '/broken', 500);
        $this->rollUp();

        $rows = $this->report()->top(RollupDimension::Status);

        $this->assertSame(['200', '404', '500'], array_map(fn ($row) => $row->value, $rows));
        $this->assertSame([3, 2, 1], array_map(fn ($row) => $row->pageViews, $rows));
        $this->assertSame([2, 1, 1], array_map(fn ($row) => $row->users, $rows));
        $this->assertTrue($rows[0]->usersExact);
    }

    public function test_error_paths_rank_by_page_views_even_though_sessions_tie_at_zero(): void
    {
        $this->pageView('v1', '/rare', 404);
        $this->pageView('v2', '/popular', 404);
        $this->pageView('v3', '/popular', 404);
        $this->pageView('v3', '/popular', 404, '2026-03-09 10:00:00');
        $this->pageView('v4', '/checkout', 500);
        $this->pageView('v5', '/fine', 200);
        $this->rollUp();

        $rows = $this->report()->top(RollupDimension::ErrorPath);

        $this->assertSame(['404 /popular', '404 /rare', '500 /checkout'], array_map(fn ($row) => $row->value, $rows));
        $this->assertSame([3, 1, 1], array_map(fn ($row) => $row->pageViews, $rows));
        $this->assertSame([2, 1, 1], array_map(fn ($row) => $row->users, $rows));
    }

    public function test_error_path_users_keep_case_variants_and_spaces_in_paths_apart(): void
    {
        $this->pageView('v1', '/About', 404);
        $this->pageView('v2', '/about', 404);
        $this->pageView('v3', '/about', 404);
        $this->pageView('v4', '/my page', 404);
        $this->pageView('v4', '/my page', 500);
        $this->rollUp();

        $rows = collect($this->report()->top(RollupDimension::ErrorPath))->mapWithKeys(fn ($row) => [$row->value => $row->users]);

        $this->assertSame(1, $rows['404 /About']);
        $this->assertSame(2, $rows['404 /about']);
        $this->assertSame(1, $rows['404 /my page']);
        $this->assertSame(1, $rows['500 /my page']);
    }

    public function test_page_views_without_a_status_do_not_appear(): void
    {
        $this->pageView('v1', '/spa', null);
        $this->rollUp();

        $this->assertSame([], $this->report()->top(RollupDimension::Status));
        $this->assertSame([], $this->report()->top(RollupDimension::ErrorPath));
    }

    public function test_pruned_days_make_status_and_error_path_rows_inexact(): void
    {
        $this->pageView('v1', '/gone', 404);
        $this->pageView('v2', '/gone', 404, '2026-03-09 10:00:00');
        $this->rollUp();
        AnalyticsEvent::query()->where('occurred_at', '<', Carbon::parse('2026-03-09'))->delete();

        $status = $this->report()->top(RollupDimension::Status)[0];
        $error = $this->report()->top(RollupDimension::ErrorPath)[0];

        $this->assertSame(2, $status->pageViews);
        $this->assertSame(2, $status->users);
        $this->assertFalse($status->usersExact);
        $this->assertSame(2, $error->users);
        $this->assertFalse($error->usersExact);
    }
}
