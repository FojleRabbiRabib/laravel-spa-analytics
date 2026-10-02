<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsTimeseriesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function visit(string $visitor, string $time): void
    {
        $session = AnalyticsSession::factory()->create([
            'visitor_id' => $visitor,
            'started_at' => Carbon::parse($time),
            'last_seen_at' => Carbon::parse($time),
            'page_views' => 1,
        ]);

        AnalyticsEvent::factory()->create([
            'visitor_id' => $visitor,
            'session_id' => $session->id,
            'occurred_at' => Carbon::parse($time),
        ]);
    }

    private function rollUp(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-07 00:00:00')->toImmutable());
    }

    public function test_daily_points_cover_every_day_and_fill_gaps_with_zeros(): void
    {
        $this->visit('v1', '2026-03-08 10:00:00');
        $this->visit('v2', '2026-03-08 11:00:00');
        $this->visit('v3', '2026-03-09 10:00:00');
        $this->rollUp();

        $points = Stats::between(Carbon::parse('2026-03-07'), Carbon::parse('2026-03-10'))->timeseries(RollupPeriod::Day);

        $this->assertSame(
            ['2026-03-07 00:00:00' => 0, '2026-03-08 00:00:00' => 2, '2026-03-09 00:00:00' => 1],
            collect($points)->mapWithKeys(fn ($point) => [$point->start->toDateTimeString() => $point->pageViews])->all(),
        );
    }

    public function test_hourly_points_have_one_entry_per_hour(): void
    {
        $this->visit('v1', '2026-03-08 10:15:00');
        $this->rollUp();

        $points = Stats::between(Carbon::parse('2026-03-08 09:00:00'), Carbon::parse('2026-03-08 12:00:00'))->timeseries(RollupPeriod::Hour);

        $this->assertSame([0, 1, 0], array_map(fn ($point) => $point->pageViews, $points));
    }

    public function test_a_partial_edge_day_is_built_from_its_hours(): void
    {
        $this->visit('early', '2026-03-08 08:00:00');
        $this->visit('late', '2026-03-08 20:00:00');
        $this->rollUp();

        $points = Stats::between(Carbon::parse('2026-03-08 12:00:00'), Carbon::parse('2026-03-09 00:00:00'))->timeseries(RollupPeriod::Day);

        $this->assertCount(1, $points);
        $this->assertSame(1, $points[0]->pageViews);
    }

    public function test_the_series_stops_at_the_last_rolled_up_hour_and_is_empty_without_rollups(): void
    {
        $this->assertSame([], Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-09'))->timeseries());

        $this->rollUp();

        $points = Stats::between(Carbon::parse('2026-03-10 10:00:00'), Carbon::parse('2026-03-11 00:00:00'))->timeseries(RollupPeriod::Hour);

        $this->assertSame('2026-03-10 11:00:00', end($points)->start->toDateTimeString());
    }

    public function test_points_serialise_to_json_friendly_values(): void
    {
        $this->visit('v1', '2026-03-08 10:00:00');
        $this->rollUp();

        $point = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-09'))->timeseries()[0];

        $this->assertSame(1, $point->toArray()['pageViews']);
        $this->assertNotFalse(json_encode($point->toArray()));
    }
}
