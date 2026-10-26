<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\RollupCompleted;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\DimensionSettings;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupBuilder;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class RollupRobustnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function pageView(string $at, array $attributes = []): AnalyticsEvent
    {
        return AnalyticsEvent::factory()->create(['occurred_at' => Carbon::parse($at), ...$attributes]);
    }

    private function total(RollupPeriod $period, string $start, string $column = 'page_views'): ?int
    {
        $value = AnalyticsRollup::query()->where('period', $period)->where('bucket_start', $start)->where('dimension', RollupDimension::Total)->value($column);

        return $value === null ? null : (int) $value;
    }

    private function hourBuckets(): array
    {
        return AnalyticsRollup::query()->where('period', RollupPeriod::Hour)->where('dimension', RollupDimension::Total)->orderBy('bucket_start')
            ->pluck('bucket_start')->map(fn ($bucket) => $bucket->toDateTimeString())->all();
    }

    public function test_two_error_paths_that_share_their_first_508_characters_add_up_instead_of_overwriting(): void
    {
        $this->pageView('2026-03-09 10:00:00', ['visitor_id' => 'v1', 'status' => 404, 'path' => '/'.str_repeat('a', 510).'x']);
        $this->pageView('2026-03-09 10:01:00', ['visitor_id' => 'v3', 'status' => 404, 'path' => '/'.str_repeat('a', 510).'x']);
        $this->pageView('2026-03-09 10:05:00', ['visitor_id' => 'v2', 'status' => 404, 'path' => '/'.str_repeat('a', 510).'y']);
        $this->pageView('2026-03-09 10:06:00', ['visitor_id' => 'v2', 'status' => 404, 'path' => '/'.str_repeat('a', 510).'y']);

        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-09 00:00:00')->toImmutable(), [RollupPeriod::Day]);

        $row = AnalyticsRollup::query()->where('period', RollupPeriod::Day)->where('bucket_start', '2026-03-09 00:00:00')->where('dimension', RollupDimension::ErrorPath)->sole();
        $this->assertSame(4, $row->page_views);
        $this->assertSame(2, $row->visitors);
    }

    public function test_a_bucket_that_throws_is_logged_and_counted_and_the_later_buckets_are_still_built(): void
    {
        Log::spy();
        $this->app->bind(RollupBuilder::class, fn () => new class(app(DimensionSettings::class)) extends RollupBuilder
        {
            public function rollup(RollupPeriod $period, CarbonImmutable $start): void
            {
                if ($period->is(RollupPeriod::Hour) && $start->format('H') === '10') {
                    throw new \RuntimeException('secret 203.0.113.9');
                }

                parent::rollup($period, $start);
            }
        });
        $seen = [];
        Event::listen(RollupCompleted::class, function (RollupCompleted $event) use (&$seen): void {
            $seen[] = $event;
        });

        $counts = app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-10 09:00:00')->toImmutable(), [RollupPeriod::Hour]);

        $this->assertSame(1, $counts['failed']);
        $this->assertSame(['2026-03-10 09:00:00', '2026-03-10 11:00:00'], $this->hourBuckets());
        $this->assertSame(1, $seen[0]->failed);
        Log::shouldHaveReceived('warning')->with('spa-analytics: rollup bucket failed', ['exception' => \RuntimeException::class, 'code' => 0])->once();
    }

    public function test_the_command_fails_when_a_bucket_failed(): void
    {
        $this->app->bind(RollupBuilder::class, fn () => new class(app(DimensionSettings::class)) extends RollupBuilder
        {
            public function rollup(RollupPeriod $period, CarbonImmutable $start): void
            {
                throw new \RuntimeException;
            }
        });

        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-10 10:00:00', '--period' => 'hour'])
            ->expectsOutputToContain('failed')
            ->assertExitCode(1);
    }

    public function test_late_events_keep_their_day_from_being_pruned_until_the_day_is_rebuilt(): void
    {
        config()->set('spa-analytics.retention_days', 3);
        $this->pageView('2026-03-05 10:00:00');
        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-05', '--period' => 'day'])->assertExitCode(0);
        $this->pageView('2026-03-05 23:30:00');
        $this->pageView('2026-03-05 23:40:00');

        $this->artisan('spa-analytics:prune')->expectsOutputToContain('2026-03-05')->assertExitCode(1);
        $this->assertSame(3, AnalyticsEvent::query()->count());

        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-05', '--period' => 'day'])->assertExitCode(0);
        $this->assertSame(3, $this->total(RollupPeriod::Day, '2026-03-05 00:00:00'));

        $this->artisan('spa-analytics:prune')->assertExitCode(0);
        $this->assertSame(0, AnalyticsEvent::query()->count());
        $this->assertSame(3, $this->total(RollupPeriod::Day, '2026-03-05 00:00:00'));
    }

    public function test_a_blocked_prune_is_logged(): void
    {
        Log::spy();
        config()->set('spa-analytics.retention_days', 3);
        $this->pageView('2026-03-05 10:00:00');

        $this->artisan('spa-analytics:prune')->assertExitCode(1);

        Log::shouldHaveReceived('warning')->with('spa-analytics: prune blocked', ['day' => '2026-03-05', 'reason' => 'missing'])->once();
    }

    public function test_a_day_before_the_oldest_raw_row_that_has_a_rollup_is_left_alone(): void
    {
        AnalyticsRollup::factory()->create(['period' => RollupPeriod::Day, 'bucket_start' => Carbon::parse('2026-03-01'), 'dimension' => RollupDimension::Total, 'value' => '', 'page_views' => 42]);
        $this->pageView('2026-03-05 10:00:00');

        $counts = app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-01 00:00:00')->toImmutable(), [RollupPeriod::Day]);

        $this->assertSame(42, $this->total(RollupPeriod::Day, '2026-03-01 00:00:00'));
        $this->assertSame(1, $counts['skippedDays']);
    }

    public function test_a_pruned_day_with_a_few_late_rows_is_never_rebuilt_to_a_smaller_total(): void
    {
        AnalyticsRollup::factory()->create(['period' => RollupPeriod::Day, 'bucket_start' => Carbon::parse('2026-03-02'), 'dimension' => RollupDimension::Total, 'value' => '', 'page_views' => 100]);
        $this->pageView('2026-03-02 09:00:00');
        $this->pageView('2026-03-08 09:00:00');

        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-02'])->assertExitCode(0);

        $this->assertSame(100, $this->total(RollupPeriod::Day, '2026-03-02 00:00:00'));
    }

    public function test_a_pruned_day_that_only_holds_other_rows_keeps_them_when_it_is_rolled_up_again(): void
    {
        AnalyticsRollup::factory()->create(['period' => RollupPeriod::Day, 'bucket_start' => Carbon::parse('2026-03-02'), 'dimension' => RollupDimension::Total, 'value' => '']);
        AnalyticsRollup::factory()->create(['period' => RollupPeriod::Day, 'bucket_start' => Carbon::parse('2026-03-02'), 'dimension' => RollupDimension::OutboundHost, 'value' => 'example.org', 'events' => 7]);

        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-02'])->assertExitCode(0);

        $this->assertSame(7, AnalyticsRollup::query()->where('dimension', RollupDimension::OutboundHost)->where('value', 'example.org')->value('events'));
    }

    public function test_custom_events_without_a_name_do_not_make_a_day_look_behind(): void
    {
        config()->set('spa-analytics.retention_days', 3);
        $this->pageView('2026-03-05 10:00:00', ['type' => 'custom', 'name' => null]);
        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-05', '--period' => 'day'])->assertExitCode(0);

        $this->artisan('spa-analytics:prune')->assertExitCode(0);

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_the_scheduled_run_only_builds_the_periods_asked_for(): void
    {
        $this->pageView('2026-03-07 10:00:00');

        $this->artisan('spa-analytics:rollup', ['--period' => 'day'])->assertExitCode(0);

        $this->assertSame([], $this->hourBuckets());
        $this->assertSame(1, $this->total(RollupPeriod::Day, '2026-03-07 00:00:00'));
    }

    public function test_the_scheduled_run_fills_a_day_and_its_hours_missed_by_a_long_scheduler_gap(): void
    {
        Carbon::setTestNow('2026-03-11 03:20:00');
        $this->pageView('2026-03-10 23:10:00');
        $this->pageView('2026-03-10 23:50:00');

        $this->artisan('spa-analytics:rollup')->assertExitCode(0);

        $this->assertSame(2, $this->total(RollupPeriod::Day, '2026-03-10 00:00:00'));
        $this->assertSame(2, $this->total(RollupPeriod::Hour, '2026-03-10 23:00:00'));
        $this->assertFalse(Stats::between(Carbon::parse('2026-03-10'), Carbon::parse('2026-03-11'))->withoutLive()->summary()->incomplete);
    }

    public function test_the_scheduled_run_rebuilds_a_recent_day_whose_raw_events_exceed_its_rollup(): void
    {
        Carbon::setTestNow('2026-03-10 12:30:00');
        $this->pageView('2026-03-08 10:00:00');
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08 00:00:00')->toImmutable());
        $this->pageView('2026-03-08 23:30:00');
        $this->assertSame(1, $this->total(RollupPeriod::Day, '2026-03-08 00:00:00'));

        $this->artisan('spa-analytics:rollup')->assertExitCode(0);

        $this->assertSame(2, $this->total(RollupPeriod::Day, '2026-03-08 00:00:00'));
        $this->assertSame(1, $this->total(RollupPeriod::Hour, '2026-03-08 23:00:00'));
    }

    public function test_an_explicit_since_does_not_heal_other_days(): void
    {
        $this->pageView('2026-03-06 10:00:00');

        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-09', '--period' => 'day'])->assertExitCode(0);

        $this->assertNull($this->total(RollupPeriod::Day, '2026-03-06 00:00:00'));
    }

    public function test_the_scheduled_run_only_heals_the_last_seven_days(): void
    {
        $this->pageView('2026-02-20 10:00:00');

        $this->artisan('spa-analytics:rollup')->assertExitCode(0);

        $this->assertNull($this->total(RollupPeriod::Day, '2026-02-20 00:00:00'));
    }
}
