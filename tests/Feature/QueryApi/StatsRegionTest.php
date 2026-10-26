<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsRegionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function visit(string $visitor, string $time, ?string $region): void
    {
        $session = AnalyticsSession::factory()->create([
            'visitor_id' => $visitor,
            'started_at' => Carbon::parse($time),
            'last_seen_at' => Carbon::parse($time),
            'country' => $region === null ? null : substr($region, 0, 2),
            'region' => $region,
        ]);

        AnalyticsEvent::factory()->create(['visitor_id' => $visitor, 'session_id' => $session->id, 'occurred_at' => Carbon::parse($time)]);
    }

    private function rollUp(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08 00:00:00')->toImmutable());
    }

    public function test_regions_rank_by_sessions_with_exact_users_and_skip_sessions_without_one(): void
    {
        $this->visit('v1', '2026-03-08 10:00:00', 'US-CA');
        $this->visit('v1', '2026-03-09 10:00:00', 'US-CA');
        $this->visit('v2', '2026-03-09 11:00:00', 'US-CA');
        $this->visit('v3', '2026-03-09 12:00:00', 'DE-BY');
        $this->visit('v4', '2026-03-09 13:00:00', null);
        $this->rollUp();

        $rows = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'))->top(RollupDimension::Region);

        $this->assertSame(['US-CA', 'DE-BY'], array_map(fn ($row) => $row->value, $rows));
        $this->assertSame([3, 1], array_map(fn ($row) => $row->sessions, $rows));
        $this->assertSame([2, 1], array_map(fn ($row) => $row->users, $rows));
        $this->assertTrue($rows[0]->usersExact);
    }

    public function test_a_disabled_region_dimension_is_refused(): void
    {
        config()->set('spa-analytics.rollups.disabled_dimensions', ['region']);

        $this->expectException(\InvalidArgumentException::class);

        Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'))->top(RollupDimension::Region);
    }
}
