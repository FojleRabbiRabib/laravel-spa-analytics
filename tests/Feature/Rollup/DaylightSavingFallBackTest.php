<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * On 2026-11-01 New York repeats the hour from 01:00 to 02:00, so two hours have the same local start.
 */
class DaylightSavingFallBackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'America/New_York');
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:30:00', 'America/New_York'));
    }

    private function pageView(string $visitor, string $at): void
    {
        AnalyticsEvent::factory()->create(['visitor_id' => $visitor, 'path' => '/a', 'occurred_at' => Carbon::parse($at, 'America/New_York')]);
    }

    public function test_a_rollup_over_the_repeated_hour_builds_without_a_failure(): void
    {
        $this->pageView('v1', '2026-11-01 00:30:00');
        $this->pageView('v2', '2026-11-01 01:30:00');
        $this->pageView('v3', '2026-11-01 02:30:00');

        $this->artisan('spa-analytics:rollup', ['--since' => '2026-11-01'])->assertExitCode(0);

        $day = AnalyticsRollup::query()->where('period', RollupPeriod::Day)->where('dimension', RollupDimension::Total)->where('bucket_start', '2026-11-01 00:00:00')->first();

        $this->assertSame(3, $day?->page_views);
    }

    public function test_a_report_over_the_fall_back_day_is_complete_and_counts_every_page_view(): void
    {
        $this->pageView('v1', '2026-11-01 00:30:00');
        $this->pageView('v2', '2026-11-01 01:30:00');
        $this->pageView('v3', '2026-11-01 02:30:00');
        $this->artisan('spa-analytics:rollup', ['--since' => '2026-11-01'])->assertExitCode(0);

        $summary = Stats::between(Carbon::parse('2026-11-01 00:00:00', 'America/New_York'), Carbon::parse('2026-11-02 00:00:00', 'America/New_York'))->summary();

        $this->assertSame(3, $summary->pageViews);
        $this->assertFalse($summary->incomplete);
    }

    public function test_the_hourly_series_over_the_fall_back_day_adds_up_to_the_summary(): void
    {
        $this->pageView('v1', '2026-11-01 00:30:00');
        $this->pageView('v2', '2026-11-01 01:30:00');
        $this->pageView('v3', '2026-11-01 02:30:00');
        $this->artisan('spa-analytics:rollup', ['--since' => '2026-11-01', '--period' => 'hour'])->assertExitCode(0);

        $report = Stats::between(Carbon::parse('2026-11-01 00:00:00', 'America/New_York'), Carbon::parse('2026-11-01 04:00:00', 'America/New_York'));
        $series = $report->timeseries(RollupPeriod::Hour);

        $this->assertSame($report->summary()->pageViews, array_sum(array_map(fn ($point): int => $point->pageViews, $series)));
        $this->assertSame(array_values(array_unique(array_map(fn ($point): string => $point->start->toDateTimeString(), $series))), array_map(fn ($point): string => $point->start->toDateTimeString(), $series));
    }

    public function test_an_hourly_report_over_the_repeated_hour_is_complete(): void
    {
        $this->pageView('v1', '2026-11-01 01:30:00');
        $this->artisan('spa-analytics:rollup', ['--since' => '2026-11-01', '--period' => 'hour'])->assertExitCode(0);

        $summary = Stats::between(Carbon::parse('2026-11-01 00:00:00', 'America/New_York'), Carbon::parse('2026-11-01 04:00:00', 'America/New_York'))->summary();

        $this->assertFalse($summary->incomplete);
        $this->assertSame(1, $summary->pageViews);
    }
}
