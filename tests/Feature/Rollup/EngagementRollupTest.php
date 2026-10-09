<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupBuilder;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class EngagementRollupTest extends TestCase
{
    private function engagement(string $visitor, string $path, int $seconds, bool $bot = false): void
    {
        AnalyticsEvent::factory()->engagement($seconds)->create([
            'visitor_id' => $visitor,
            'path' => $path,
            'is_bot' => $bot,
            'occurred_at' => Carbon::parse('2026-03-02 10:15:00'),
        ]);
    }

    private function build(): void
    {
        app(RollupBuilder::class)->rollup(RollupPeriod::Hour, CarbonImmutable::parse('2026-03-02 10:00:00'));
    }

    private function seconds(RollupDimension $dimension, string $value): ?int
    {
        return AnalyticsRollup::query()
            ->where('period', RollupPeriod::Hour)
            ->where('dimension', $dimension)
            ->where('value_hash', sha1($value))
            ->value('engaged_seconds');
    }

    public function test_the_seconds_are_summed_in_total_and_per_path(): void
    {
        $this->engagement('v1', '/a', 40);
        $this->engagement('v1', '/a', 20);
        $this->engagement('v2', '/b', 30);
        $this->engagement('bot', '/a', 500, bot: true);

        $this->build();

        $this->assertSame(90, $this->seconds(RollupDimension::Total, ''));
        $this->assertSame(60, $this->seconds(RollupDimension::Path, '/a'));
        $this->assertSame(30, $this->seconds(RollupDimension::Path, '/b'));
    }

    public function test_paths_that_differ_only_in_case_stay_separate(): void
    {
        $this->engagement('v1', '/About', 10);
        $this->engagement('v2', '/about', 25);

        $this->build();

        $this->assertSame(10, $this->seconds(RollupDimension::Path, '/About'));
        $this->assertSame(25, $this->seconds(RollupDimension::Path, '/about'));
    }

    public function test_engagement_is_not_counted_as_an_event_or_a_page_view(): void
    {
        $this->engagement('v1', '/a', 40);

        $this->build();

        $total = AnalyticsRollup::query()->where('dimension', RollupDimension::Total)->sole();

        $this->assertSame(0, $total->events);
        $this->assertSame(0, $total->page_views);
        $this->assertSame(0, $total->visitors);
        $this->assertSame(0, AnalyticsRollup::query()->where('dimension', RollupDimension::Event)->count());
    }

    public function test_a_disabled_path_dimension_keeps_the_total(): void
    {
        config()->set('spa-analytics.rollups.disabled_dimensions', ['path']);
        $this->engagement('v1', '/a', 40);

        $this->build();

        $this->assertSame(40, $this->seconds(RollupDimension::Total, ''));
        $this->assertSame(0, AnalyticsRollup::query()->where('dimension', RollupDimension::Path)->count());
    }

    public function test_a_bucket_without_engagement_has_zero_seconds(): void
    {
        $this->build();

        $this->assertSame(0, $this->seconds(RollupDimension::Total, ''));
    }
}
