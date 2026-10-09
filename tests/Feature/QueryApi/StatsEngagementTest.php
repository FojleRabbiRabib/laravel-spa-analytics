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

class StatsEngagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function visit(string $visitor, string $time, string $path, int $seconds): void
    {
        $at = Carbon::parse($time);
        $session = AnalyticsSession::factory()->create(['visitor_id' => $visitor, 'started_at' => $at, 'last_seen_at' => $at, 'entry_path' => $path, 'exit_path' => $path]);

        AnalyticsEvent::factory()->create(['visitor_id' => $visitor, 'session_id' => $session->id, 'path' => $path, 'occurred_at' => $at]);
        AnalyticsEvent::factory()->engagement($seconds)->create(['visitor_id' => $visitor, 'session_id' => $session->id, 'path' => $path, 'occurred_at' => $at->copy()->addMinutes(2)]);
    }

    private function rollUp(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08 00:00:00')->toImmutable());
    }

    private function report()
    {
        return Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'));
    }

    public function test_the_summary_reports_total_and_average_engagement_per_user_and_per_session(): void
    {
        $this->visit('v1', '2026-03-08 10:00:00', '/a', 40);
        $this->visit('v1', '2026-03-09 10:00:00', '/a', 20);
        $this->visit('v2', '2026-03-08 11:00:00', '/b', 30);
        $this->rollUp();

        $summary = $this->report()->summary();

        $this->assertSame(90, $summary->engagedSeconds);
        $this->assertSame(45.0, $summary->avgEngagementPerUser);
        $this->assertSame(30.0, $summary->avgEngagementPerSession);
        $this->assertSame(3, $summary->pageViews);
        $this->assertSame(0, $summary->events);
    }

    public function test_paths_report_engagement_per_user_of_that_page(): void
    {
        $this->visit('v1', '2026-03-08 10:00:00', '/a', 40);
        $this->visit('v1', '2026-03-09 10:00:00', '/a', 20);
        $this->visit('v2', '2026-03-08 11:00:00', '/a', 30);
        $this->visit('v3', '2026-03-08 12:00:00', '/b', 10);
        $this->rollUp();

        $rows = collect($this->report()->top(RollupDimension::Path))->keyBy('value');

        $this->assertSame(90, $rows['/a']->engagedSeconds);
        $this->assertSame(45.0, $rows['/a']->avgEngagement);
        $this->assertSame(10.0, $rows['/b']->avgEngagement);
    }

    public function test_without_engagement_the_numbers_are_zero(): void
    {
        AnalyticsEvent::factory()->create(['visitor_id' => 'v1', 'path' => '/a', 'occurred_at' => Carbon::parse('2026-03-08 10:00:00')]);
        $this->rollUp();

        $summary = $this->report()->summary();
        $row = $this->report()->top(RollupDimension::Path)[0];

        $this->assertSame(0, $summary->engagedSeconds);
        $this->assertSame(0.0, $summary->avgEngagementPerUser);
        $this->assertSame(0.0, $summary->avgEngagementPerSession);
        $this->assertSame(0, $row->engagedSeconds);
        $this->assertSame(0.0, $row->avgEngagement);
    }

    public function test_an_empty_report_has_zero_engagement(): void
    {
        $summary = $this->report()->summary();

        $this->assertSame(0, $summary->engagedSeconds);
        $this->assertSame(0.0, $summary->avgEngagementPerUser);
    }

    public function test_the_summary_serialises_the_new_fields(): void
    {
        $this->visit('v1', '2026-03-08 10:00:00', '/a', 40);
        $this->rollUp();

        $array = $this->report()->summary()->toArray();

        $this->assertSame(40, $array['engagedSeconds']);
        $this->assertSame(40.0, $array['avgEngagementPerUser']);
        $this->assertSame(40.0, $array['avgEngagementPerSession']);
        $this->assertNotFalse(json_encode($array));
    }
}
