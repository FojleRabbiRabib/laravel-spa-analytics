<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class RollupCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 14:20:00');
    }

    private function buckets(RollupPeriod $period): array
    {
        return AnalyticsRollup::query()
            ->where('period', $period)
            ->where('dimension', RollupDimension::Total)
            ->orderBy('bucket_start')
            ->pluck('bucket_start')
            ->map(fn ($bucket) => $bucket->toDateTimeString())
            ->all();
    }

    private function pageView(string $at): void
    {
        AnalyticsEvent::factory()->create(['occurred_at' => Carbon::parse($at)]);
    }

    public function test_the_default_run_recomputes_the_lookback_hours_that_have_ended(): void
    {
        $this->pageView('2026-03-10 13:05:00');

        $this->artisan('spa-analytics:rollup')->assertExitCode(0);

        $this->assertSame(
            ['2026-03-10 11:00:00', '2026-03-10 12:00:00', '2026-03-10 13:00:00'],
            $this->buckets(RollupPeriod::Hour),
        );
        $this->assertSame([], $this->buckets(RollupPeriod::Day));
        $this->assertSame(1, AnalyticsRollup::query()->where('period', RollupPeriod::Hour)->where('bucket_start', '2026-03-10 13:00:00')->where('dimension', RollupDimension::Total)->value('page_views'));
    }

    public function test_the_running_hour_and_the_running_day_are_never_built(): void
    {
        $this->pageView('2026-03-10 14:05:00');

        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-10'])->assertExitCode(0);

        $this->assertNotContains('2026-03-10 14:00:00', $this->buckets(RollupPeriod::Hour));
        $this->assertSame([], $this->buckets(RollupPeriod::Day));
    }

    public function test_a_gap_in_the_schedule_leaves_no_partial_bucket_that_looks_complete(): void
    {
        Carbon::setTestNow('2026-03-10 23:20:00');
        $this->pageView('2026-03-10 23:10:00');
        $this->artisan('spa-analytics:rollup')->assertExitCode(0);

        Carbon::setTestNow('2026-03-11 03:20:00');
        $this->pageView('2026-03-10 23:50:00');
        $this->artisan('spa-analytics:rollup')->assertExitCode(0);

        $this->assertNotContains('2026-03-10 23:00:00', $this->buckets(RollupPeriod::Hour));
        $this->assertNotContains('2026-03-10 00:00:00', $this->buckets(RollupPeriod::Day));
        $this->assertTrue(Stats::between(Carbon::parse('2026-03-10'), Carbon::parse('2026-03-11'))->summary()->incomplete);
    }

    public function test_a_lookback_of_zero_still_builds_the_hour_that_just_ended(): void
    {
        config()->set('spa-analytics.rollups.lookback_hours', 0);
        Carbon::setTestNow('2026-03-10 14:05:00');

        $this->artisan('spa-analytics:rollup')->assertExitCode(0);

        $this->assertSame(['2026-03-10 13:00:00'], $this->buckets(RollupPeriod::Hour));
    }

    public function test_a_lookback_across_midnight_recomputes_the_day_that_ended_but_not_the_new_one(): void
    {
        Carbon::setTestNow('2026-03-10 01:20:00');

        $this->artisan('spa-analytics:rollup')->assertExitCode(0);

        $this->assertSame(['2026-03-09 00:00:00'], $this->buckets(RollupPeriod::Day));
    }

    public function test_since_backfills_the_hours_and_days_that_have_ended(): void
    {
        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-08 22:00:00'])->assertExitCode(0);

        $this->assertSame(['2026-03-08 00:00:00', '2026-03-09 00:00:00'], $this->buckets(RollupPeriod::Day));
        $this->assertCount(40, $this->buckets(RollupPeriod::Hour));
    }

    public function test_the_period_option_limits_what_is_computed(): void
    {
        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-09', '--period' => 'day'])->assertExitCode(0);

        $this->assertSame([], $this->buckets(RollupPeriod::Hour));
        $this->assertSame(['2026-03-09 00:00:00'], $this->buckets(RollupPeriod::Day));
    }

    public function test_an_invalid_since_or_period_fails(): void
    {
        $this->artisan('spa-analytics:rollup', ['--since' => 'not a date'])->assertExitCode(1);
        $this->artisan('spa-analytics:rollup', ['--period' => 'week'])->assertExitCode(1);

        $this->assertSame(0, AnalyticsRollup::query()->count());
    }

    public function test_a_run_that_overlaps_another_is_skipped(): void
    {
        Cache::lock('spa-analytics:rollup', 60)->get();

        $this->artisan('spa-analytics:rollup')
            ->expectsOutputToContain('already running')
            ->assertExitCode(0);

        $this->assertSame(0, AnalyticsRollup::query()->count());
    }

    public function test_the_lock_is_released_after_a_run(): void
    {
        $this->artisan('spa-analytics:rollup')->assertExitCode(0);

        $this->assertTrue(Cache::lock('spa-analytics:rollup', 5)->get());
    }

    public function test_days_before_the_cutoff_are_only_filled_when_they_have_no_rollup(): void
    {
        config()->set('spa-analytics.retention_days', '3');
        AnalyticsRollup::factory()->create(['period' => RollupPeriod::Day, 'bucket_start' => Carbon::parse('2026-03-05'), 'page_views' => 99]);
        $this->pageView('2026-03-06 10:00:00');

        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-04', '--period' => 'day'])
            ->expectsOutputToContain('already have rollups')
            ->assertExitCode(0);

        $day = fn (string $date) => AnalyticsRollup::query()->where('period', RollupPeriod::Day)->where('bucket_start', $date.' 00:00:00')->where('dimension', RollupDimension::Total)->value('page_views');

        $this->assertSame(99, $day('2026-03-05'));
        $this->assertSame(1, $day('2026-03-06'));
        $this->assertSame(0, $day('2026-03-04'));
    }

    public function test_the_package_schedules_the_rollup_and_the_prune(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode(' | ');

        $this->assertStringContainsString('spa-analytics:rollup', $commands);
        $this->assertStringContainsString('spa-analytics:prune', $commands);
    }
}
