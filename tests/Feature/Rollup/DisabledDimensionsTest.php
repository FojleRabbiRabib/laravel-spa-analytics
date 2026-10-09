<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ViewportSize;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\DimensionSettings;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupBuilder;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;

class DisabledDimensionsTest extends TestCase
{
    /**
     * Every dimension that can be turned off, so a dimension added later is checked without a new test.
     *
     * @return array<string, array{RollupDimension}>
     */
    public static function optionalDimensions(): array
    {
        $cases = [];

        foreach (RollupDimension::cases() as $dimension) {
            if (! in_array($dimension, RollupDimension::REQUIRED, true)) {
                $cases[$dimension->value] = [$dimension];
            }
        }

        return $cases;
    }

    /**
     * One visit that gives every dimension at least one row in the hour from 10:00.
     */
    private function seedVisit(string $at = '2026-03-02 10:05:00'): void
    {
        $time = Carbon::parse($at);
        $session = AnalyticsSession::factory()->create([
            'visitor_id' => 'v1',
            'started_at' => $time,
            'last_seen_at' => $time->copy()->addMinutes(10),
            'page_views' => 2,
            'entry_path' => '/a',
            'exit_path' => '/b',
            'referrer_host' => 'google.com',
            'referrer_type' => ReferrerType::Search,
            'utm_source' => 's',
            'utm_medium' => 'm',
            'utm_campaign' => 'c',
            'utm_term' => 't',
            'utm_content' => 'x',
            'device_type' => DeviceType::Mobile,
            'os' => 'Linux',
            'browser' => 'Firefox',
            'country' => 'BD',
            'viewport' => ViewportSize::Md,
            'is_new_visitor' => true,
        ]);

        $event = fn (array $attributes = []) => ['visitor_id' => 'v1', 'session_id' => $session->id, 'occurred_at' => $time->copy()->addMinute(), ...$attributes];

        AnalyticsEvent::factory()->create($event(['path' => '/a', 'language' => 'en-US', 'status' => 200]));
        AnalyticsEvent::factory()->create($event(['path' => '/missing', 'language' => 'en-US', 'status' => 404]));
        AnalyticsEvent::factory()->custom()->create($event(['name' => 'clicked']));
        AnalyticsEvent::factory()->goal()->create($event(['name' => 'signup', 'value' => 5]));
        AnalyticsEvent::factory()->outboundClick()->create($event(['target_host' => 'example.org']));
        AnalyticsEvent::factory()->scrollDepth(50)->create($event());
        AnalyticsEvent::factory()->download('/files/a.pdf', 'pdf')->create($event());
    }

    private function build(string $hour = '2026-03-02 10:00:00'): void
    {
        app(RollupBuilder::class)->rollup(RollupPeriod::Hour, CarbonImmutable::parse($hour));
    }

    /**
     * @return array<string, int>
     */
    private function rowsPerDimension(): array
    {
        $counts = [];

        foreach (RollupDimension::cases() as $dimension) {
            $counts[$dimension->value] = AnalyticsRollup::query()->where('dimension', $dimension)->count();
        }

        return $counts;
    }

    public function test_the_seed_gives_every_dimension_at_least_one_row_when_nothing_is_disabled(): void
    {
        $this->seedVisit();
        $this->build();

        foreach ($this->rowsPerDimension() as $dimension => $rows) {
            $this->assertGreaterThan(0, $rows, "{$dimension} has no rows");
        }
    }

    #[DataProvider('optionalDimensions')]
    public function test_a_disabled_dimension_gets_no_rows_and_the_others_are_unchanged(RollupDimension $dimension): void
    {
        $this->seedVisit();
        $this->build();
        $baseline = $this->rowsPerDimension();
        AnalyticsRollup::query()->delete();

        config()->set('spa-analytics.rollups.disabled_dimensions', [$dimension->value]);
        $this->build();

        $after = $this->rowsPerDimension();

        $this->assertSame(0, $after[$dimension->value]);

        unset($baseline[$dimension->value], $after[$dimension->value]);

        $this->assertSame($baseline, $after);
    }

    public function test_the_total_still_counts_events_when_the_event_dimension_is_disabled(): void
    {
        $this->seedVisit();
        config()->set('spa-analytics.rollups.disabled_dimensions', ['event']);

        $this->build();

        $total = AnalyticsRollup::query()->where('dimension', RollupDimension::Total)->sole();

        $this->assertSame(2, $total->events);
        $this->assertSame('5.00', $total->revenue);
    }

    public function test_required_and_unknown_names_are_ignored_and_reported(): void
    {
        config()->set('spa-analytics.rollups.disabled_dimensions', ['total', 'goal', 'visitor_type', 'bogus', ' path ', 'path', 42]);
        $settings = app(DimensionSettings::class);

        $this->assertSame([RollupDimension::Path], $settings->disabled());
        $this->assertFalse($settings->isEnabled(RollupDimension::Path));
        $this->assertTrue($settings->isEnabled(RollupDimension::Goal));
        $this->assertCount(4, $settings->problems());

        $this->seedVisit();
        $this->build();

        foreach ([RollupDimension::Total, RollupDimension::Goal, RollupDimension::VisitorType] as $required) {
            $this->assertGreaterThan(0, AnalyticsRollup::query()->where('dimension', $required)->count());
        }
    }

    public function test_a_config_without_the_key_disables_nothing(): void
    {
        config()->set('spa-analytics.rollups', ['schedule' => true, 'lookback_hours' => 3]);

        $this->assertSame([], app(DimensionSettings::class)->disabled());
        $this->assertSame([], app(DimensionSettings::class)->problems());
    }

    public function test_rebuilding_a_bucket_keeps_the_stored_rows_of_a_disabled_dimension(): void
    {
        $this->seedVisit();
        $this->build();
        $before = AnalyticsRollup::query()->where('dimension', RollupDimension::UtmTerm)->get(['value', 'sessions'])->toArray();

        config()->set('spa-analytics.rollups.disabled_dimensions', ['utm_term']);
        $this->build();

        $this->assertNotSame([], $before);
        $this->assertSame($before, AnalyticsRollup::query()->where('dimension', RollupDimension::UtmTerm)->get(['value', 'sessions'])->toArray());
        $this->assertSame(1, AnalyticsRollup::query()->where('dimension', RollupDimension::Total)->count());
    }

    public function test_the_purge_flag_cannot_be_combined_with_since_or_period(): void
    {
        $this->artisan('spa-analytics:rollup', ['--purge-disabled' => true, '--since' => '2026-01-01'])->assertExitCode(1);
        $this->artisan('spa-analytics:rollup', ['--purge-disabled' => true, '--period' => 'hour'])->assertExitCode(1);
    }

    public function test_the_purge_flag_is_skipped_while_another_rollup_holds_the_lock(): void
    {
        $this->seedVisit();
        $this->build();
        config()->set('spa-analytics.rollups.disabled_dimensions', ['utm_term']);
        $rows = AnalyticsRollup::query()->count();
        Cache::lock('spa-analytics:rollup', 60)->get();

        $this->artisan('spa-analytics:rollup', ['--purge-disabled' => true])
            ->expectsOutputToContain('already running')
            ->assertExitCode(0);

        $this->assertSame($rows, AnalyticsRollup::query()->count());
    }

    public function test_the_purge_works_in_several_chunks(): void
    {
        $this->seedVisit();
        $this->build();
        config()->set('spa-analytics.rollups.disabled_dimensions', ['utm_term', 'utm_content', 'download']);
        $rows = AnalyticsRollup::query()->whereIn('dimension', [RollupDimension::UtmTerm, RollupDimension::UtmContent, RollupDimension::Download])->count();

        $this->assertSame($rows, app(RollupRunner::class)->purgeDisabled(1));
        $this->assertSame(0, AnalyticsRollup::query()->whereIn('dimension', [RollupDimension::UtmTerm, RollupDimension::UtmContent, RollupDimension::Download])->count());
    }

    public function test_the_purge_flag_deletes_only_the_disabled_dimensions_in_both_periods(): void
    {
        $this->seedVisit();
        $this->build();
        app(RollupBuilder::class)->rollup(RollupPeriod::Day, CarbonImmutable::parse('2026-03-02'));
        $path = AnalyticsRollup::query()->where('dimension', RollupDimension::Path)->count();
        $term = AnalyticsRollup::query()->where('dimension', RollupDimension::UtmTerm)->count();
        $download = AnalyticsRollup::query()->where('dimension', RollupDimension::Download)->count();
        $total = AnalyticsRollup::query()->count();

        config()->set('spa-analytics.rollups.disabled_dimensions', ['utm_term', 'download', 'goal']);

        $this->artisan('spa-analytics:rollup', ['--purge-disabled' => true])
            ->expectsOutputToContain('Deleted '.($term + $download).' rollup rows')
            ->expectsOutputToContain("'goal' cannot be disabled")
            ->assertExitCode(0);

        $this->assertSame(0, AnalyticsRollup::query()->where('dimension', RollupDimension::UtmTerm)->count());
        $this->assertSame(0, AnalyticsRollup::query()->where('dimension', RollupDimension::Download)->count());
        $this->assertSame($path, AnalyticsRollup::query()->where('dimension', RollupDimension::Path)->count());
        $this->assertSame($total - $term - $download, AnalyticsRollup::query()->count());
        $this->assertGreaterThan(0, AnalyticsRollup::query()->where('dimension', RollupDimension::Goal)->count());
    }

    public function test_the_purge_flag_with_nothing_disabled_deletes_nothing(): void
    {
        $this->seedVisit();
        $this->build();
        $total = AnalyticsRollup::query()->count();

        $this->artisan('spa-analytics:rollup', ['--purge-disabled' => true])
            ->expectsOutputToContain('Deleted 0 rollup rows')
            ->assertExitCode(0);

        $this->assertSame($total, AnalyticsRollup::query()->count());
    }
}
