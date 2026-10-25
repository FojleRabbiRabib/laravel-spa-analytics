<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupBuilder;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class PathScrollRollupTest extends TestCase
{
    private function scroll(string $visitor, ?string $path, int $percent, bool $bot = false): void
    {
        AnalyticsEvent::factory()->create([
            'type' => EventType::ScrollDepth,
            'visitor_id' => $visitor,
            'name' => null,
            'path' => $path,
            'scroll_percent' => $percent,
            'is_bot' => $bot,
            'occurred_at' => Carbon::parse('2026-03-02 10:05:00'),
        ]);
    }

    private function build(): void
    {
        app(RollupBuilder::class)->rollup(RollupPeriod::Hour, CarbonImmutable::parse('2026-03-02 10:00:00'));
    }

    private function row(string $value): ?AnalyticsRollup
    {
        return AnalyticsRollup::query()
            ->where('period', RollupPeriod::Hour)
            ->where('dimension', RollupDimension::PathScroll)
            ->where('value_hash', sha1($value))
            ->first();
    }

    public function test_each_milestone_of_each_path_gets_events_and_visitors(): void
    {
        $this->scroll('v1', '/pricing', 25);
        $this->scroll('v1', '/pricing', 50);
        $this->scroll('v1', '/pricing', 50);
        $this->scroll('v2', '/pricing', 50);
        $this->scroll('v2', '/docs', 25);

        $this->build();

        $this->assertSame(1, $this->row('25 /pricing')->events);
        $this->assertSame(3, $this->row('50 /pricing')->events);
        $this->assertSame(2, $this->row('50 /pricing')->visitors);
        $this->assertSame(1, $this->row('25 /docs')->visitors);
        $this->assertNull($this->row('100 /pricing'));
    }

    public function test_paths_that_differ_only_in_case_stay_apart(): void
    {
        $this->scroll('v1', '/About', 50);
        $this->scroll('v2', '/about', 50);

        $this->build();

        $this->assertSame(1, $this->row('50 /About')->events);
        $this->assertSame(1, $this->row('50 /about')->events);
    }

    public function test_bots_and_events_without_a_path_add_no_rows(): void
    {
        $this->scroll('bot', '/pricing', 50, bot: true);
        $this->scroll('v1', null, 50);

        $this->build();

        $this->assertSame(0, AnalyticsRollup::query()->where('dimension', RollupDimension::PathScroll)->count());
    }

    public function test_the_total_events_and_the_plain_scroll_depth_rows_are_unchanged(): void
    {
        $this->scroll('v1', '/pricing', 50);

        $this->build();

        $this->assertSame(0, AnalyticsRollup::query()->where('dimension', RollupDimension::Total)->value('events'));
        $this->assertSame(1, AnalyticsRollup::query()->where('dimension', RollupDimension::ScrollDepth)->where('value', '50')->value('events'));
    }

    public function test_a_disabled_dimension_builds_no_rows_in_a_bucket_or_a_preview(): void
    {
        config()->set('spa-analytics.rollups.disabled_dimensions', ['path_scroll']);
        $this->scroll('v1', '/pricing', 50);

        $this->build();
        $preview = app(RollupBuilder::class)->preview(CarbonImmutable::parse('2026-03-02 10:00:00'), CarbonImmutable::parse('2026-03-02 11:00:00'));

        $this->assertSame(0, AnalyticsRollup::query()->where('dimension', RollupDimension::PathScroll)->count());
        $this->assertArrayNotHasKey(RollupDimension::PathScroll->key('50 /pricing'), $preview);
    }

    public function test_the_preview_has_the_same_rows_as_the_stored_bucket(): void
    {
        $this->scroll('v1', '/pricing', 50);

        $preview = app(RollupBuilder::class)->preview(CarbonImmutable::parse('2026-03-02 10:00:00'), CarbonImmutable::parse('2026-03-02 11:00:00'));

        $this->assertSame(1, $preview[RollupDimension::PathScroll->key('50 /pricing')]['events']);
    }
}
