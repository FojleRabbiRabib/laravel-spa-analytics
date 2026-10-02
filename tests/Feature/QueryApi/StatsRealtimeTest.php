<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsRealtimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function pageView(string $visitor, string $time, string $path, bool $bot = false): void
    {
        AnalyticsEvent::factory()->create([
            'visitor_id' => $visitor,
            'occurred_at' => Carbon::parse($time),
            'path' => $path,
            'is_bot' => $bot,
        ]);
    }

    public function test_it_counts_visitors_and_their_latest_page_in_the_window(): void
    {
        $this->pageView('v1', '2026-03-10 12:26:00', '/pricing');
        $this->pageView('v1', '2026-03-10 12:29:00', '/docs');
        $this->pageView('v2', '2026-03-10 12:28:00', '/docs');
        $this->pageView('v3', '2026-03-10 12:27:00', '/pricing');

        $realtime = Stats::realtime();

        $this->assertSame(3, $realtime->activeVisitors);
        $this->assertSame(4, $realtime->pageViews);
        $this->assertSame([['path' => '/docs', 'visitors' => 2], ['path' => '/pricing', 'visitors' => 1]], $realtime->pages);
        $this->assertSame(5, $realtime->windowMinutes);
    }

    public function test_older_views_bots_and_other_event_types_are_left_out(): void
    {
        $this->pageView('old', '2026-03-10 12:24:00', '/old');
        $this->pageView('bot', '2026-03-10 12:29:00', '/bot', true);
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'goal', 'occurred_at' => Carbon::parse('2026-03-10 12:29:00')]);

        $realtime = Stats::realtime();

        $this->assertSame(0, $realtime->activeVisitors);
        $this->assertSame([], $realtime->pages);
    }

    public function test_the_window_follows_the_config_and_serialises(): void
    {
        config(['spa-analytics.stats.realtime_minutes' => 10]);
        $this->pageView('v1', '2026-03-10 12:22:00', '/a');

        $realtime = Stats::realtime();

        $this->assertSame(1, $realtime->activeVisitors);
        $this->assertSame(['activeVisitors', 'pageViews', 'pages', 'windowMinutes', 'asOf'], array_keys($realtime->toArray()));
        $this->assertSame(10, $realtime->toArray()['windowMinutes']);
        $this->assertNotFalse(json_encode($realtime->toArray()));
    }
}
