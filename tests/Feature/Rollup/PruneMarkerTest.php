<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsState;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\PruneMarker;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class PruneMarkerTest extends TestCase
{
    private function marker(): PruneMarker
    {
        return new PruneMarker;
    }

    public function test_there_is_no_marker_until_something_was_pruned(): void
    {
        $this->assertNull($this->marker()->get());
        $this->assertFalse($this->marker()->covers(CarbonImmutable::parse('2020-01-01')));
    }

    public function test_the_first_advance_creates_the_marker(): void
    {
        $this->marker()->advance(CarbonImmutable::parse('2026-03-07'));

        $this->assertSame('2026-03-07 00:00:00', AnalyticsState::query()->find(AnalyticsState::PRUNED_THROUGH)->value);
    }

    public function test_a_later_advance_moves_the_marker_forward(): void
    {
        AnalyticsState::factory()->prunedThrough('2026-03-05 00:00:00')->create();

        $this->marker()->advance(CarbonImmutable::parse('2026-03-07'));

        $this->assertSame('2026-03-07 00:00:00', $this->marker()->get()?->toDateTimeString());
        $this->assertSame(1, AnalyticsState::query()->count());
    }

    public function test_an_earlier_advance_never_moves_the_marker_back(): void
    {
        AnalyticsState::factory()->prunedThrough('2026-03-07 00:00:00')->create();

        $this->marker()->advance(CarbonImmutable::parse('2026-03-05'));

        $this->assertSame('2026-03-07 00:00:00', $this->marker()->get()?->toDateTimeString());
    }

    public function test_a_day_is_covered_only_before_the_marker(): void
    {
        AnalyticsState::factory()->prunedThrough('2026-03-07 00:00:00')->create();

        $this->assertTrue($this->marker()->covers(CarbonImmutable::parse('2026-03-06')));
        $this->assertFalse($this->marker()->covers(CarbonImmutable::parse('2026-03-07')));
        $this->assertFalse($this->marker()->covers(CarbonImmutable::parse('2026-03-08')));
    }
}
