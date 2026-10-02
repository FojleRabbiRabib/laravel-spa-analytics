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

class ClientEventRollupTest extends TestCase
{
    private function build(): void
    {
        app(RollupBuilder::class)->rollup(RollupPeriod::Hour, CarbonImmutable::parse('2026-03-02 10:00:00'));
    }

    private function row(RollupDimension $dimension, string $value): ?AnalyticsRollup
    {
        return AnalyticsRollup::query()
            ->where('period', RollupPeriod::Hour)
            ->where('dimension', $dimension)
            ->where('value_hash', sha1($value))
            ->first();
    }

    private function outbound(string $visitor, string $host, bool $bot = false): void
    {
        AnalyticsEvent::factory()->outboundClick()->create(['visitor_id' => $visitor, 'target_host' => $host, 'occurred_at' => Carbon::parse('2026-03-02 10:15:00'), 'is_bot' => $bot]);
    }

    private function scroll(string $visitor, int $percent): void
    {
        AnalyticsEvent::factory()->scrollDepth($percent)->create(['visitor_id' => $visitor, 'occurred_at' => Carbon::parse('2026-03-02 10:20:00')]);
    }

    public function test_outbound_clicks_are_counted_per_target_host_with_distinct_visitors(): void
    {
        $this->outbound('v1', 'example.org');
        $this->outbound('v1', 'example.org');
        $this->outbound('v2', 'example.org');
        $this->outbound('v2', 'other.net');
        $this->outbound('bot', 'example.org', true);

        $this->build();

        $this->assertSame(3, $this->row(RollupDimension::OutboundHost, 'example.org')->events);
        $this->assertSame(2, $this->row(RollupDimension::OutboundHost, 'example.org')->visitors);
        $this->assertSame(1, $this->row(RollupDimension::OutboundHost, 'other.net')->events);
    }

    public function test_hosts_that_differ_only_in_case_stay_separate_rows(): void
    {
        $this->outbound('v1', 'Example.org');
        $this->outbound('v2', 'example.org');

        $this->build();

        $this->assertSame(1, $this->row(RollupDimension::OutboundHost, 'Example.org')->events);
        $this->assertSame(1, $this->row(RollupDimension::OutboundHost, 'example.org')->events);
    }

    public function test_scroll_milestones_are_counted_per_percent(): void
    {
        $this->scroll('v1', 25);
        $this->scroll('v1', 50);
        $this->scroll('v2', 25);

        $this->build();

        $this->assertSame(2, $this->row(RollupDimension::ScrollDepth, '25')->events);
        $this->assertSame(2, $this->row(RollupDimension::ScrollDepth, '25')->visitors);
        $this->assertSame(1, $this->row(RollupDimension::ScrollDepth, '50')->events);
        $this->assertNull($this->row(RollupDimension::ScrollDepth, '75'));
    }

    public function test_the_total_events_number_still_means_custom_events_and_goals_only(): void
    {
        $this->outbound('v1', 'example.org');
        $this->scroll('v1', 25);
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'v1', 'name' => 'signup', 'occurred_at' => Carbon::parse('2026-03-02 10:30:00')]);

        $this->build();

        $this->assertSame(1, $this->row(RollupDimension::Total, '')->events);
    }
}
