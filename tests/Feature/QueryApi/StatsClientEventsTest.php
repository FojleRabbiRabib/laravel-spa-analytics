<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsClientEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function outbound(string $visitor, string $time, string $host): void
    {
        AnalyticsEvent::factory()->outboundClick()->create(['visitor_id' => $visitor, 'target_host' => $host, 'occurred_at' => Carbon::parse($time)]);
    }

    private function scroll(string $visitor, string $time, int $percent): void
    {
        AnalyticsEvent::factory()->scrollDepth($percent)->create(['visitor_id' => $visitor, 'occurred_at' => Carbon::parse($time)]);
    }

    private function rollUp(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08 00:00:00')->toImmutable());
    }

    private function report()
    {
        return Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'));
    }

    public function test_outbound_hosts_are_ranked_by_clicks_with_exact_users(): void
    {
        $this->outbound('v1', '2026-03-08 10:00:00', 'example.org');
        $this->outbound('v1', '2026-03-09 10:00:00', 'example.org');
        $this->outbound('v2', '2026-03-09 11:00:00', 'example.org');
        $this->outbound('v3', '2026-03-09 12:00:00', 'other.net');
        $this->rollUp();

        $rows = $this->report()->top(RollupDimension::OutboundHost);

        $this->assertSame(['example.org', 'other.net'], array_map(fn ($row) => $row->value, $rows));
        $this->assertSame(3, $rows[0]->events);
        $this->assertSame(2, $rows[0]->users);
        $this->assertTrue($rows[0]->usersExact);
    }

    public function test_scroll_milestones_are_ranked_by_how_often_they_were_reached(): void
    {
        $this->scroll('v1', '2026-03-08 10:00:00', 25);
        $this->scroll('v1', '2026-03-08 10:01:00', 50);
        $this->scroll('v2', '2026-03-08 11:00:00', 25);
        $this->scroll('v2', '2026-03-09 11:00:00', 25);
        $this->rollUp();

        $rows = $this->report()->top(RollupDimension::ScrollDepth);

        $this->assertSame(['25', '50'], array_map(fn ($row) => $row->value, $rows));
        $this->assertSame([3, 1], array_map(fn ($row) => $row->events, $rows));
        $this->assertSame([2, 1], array_map(fn ($row) => $row->users, $rows));
    }

    public function test_pruned_days_make_a_scroll_row_inexact(): void
    {
        $this->scroll('v1', '2026-03-08 10:00:00', 25);
        $this->scroll('v2', '2026-03-09 10:00:00', 25);
        $this->rollUp();
        AnalyticsEvent::query()->where('occurred_at', '<', Carbon::parse('2026-03-09'))->delete();

        $rows = $this->report()->top(RollupDimension::ScrollDepth);

        $this->assertSame(2, $rows[0]->users);
        $this->assertFalse($rows[0]->usersExact);
    }

    public function test_the_summary_events_number_does_not_count_clicks_or_scrolls(): void
    {
        $this->outbound('v1', '2026-03-08 10:00:00', 'example.org');
        $this->scroll('v1', '2026-03-08 10:01:00', 25);
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'v1', 'name' => 'signup', 'occurred_at' => Carbon::parse('2026-03-08 10:02:00')]);
        $this->rollUp();

        $summary = $this->report()->summary();

        $this->assertSame(1, $summary->events);
        $this->assertSame(1, $summary->goalCompletions);
    }
}
