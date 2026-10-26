<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use FojleRabbiRabib\LaravelSpaAnalytics\Events\RollupCompleted;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class RollupCompletedHookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    public function test_a_run_dispatches_the_event_once_with_the_counts_it_returns(): void
    {
        $seen = [];
        Event::listen(RollupCompleted::class, function (RollupCompleted $event) use (&$seen): void {
            $seen[] = $event;
        });

        $counts = app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-09 00:00:00')->toImmutable());

        $this->assertCount(1, $seen);
        $this->assertSame([$counts['hours'], $counts['days'], $counts['skippedDays']], [$seen[0]->hours, $seen[0]->days, $seen[0]->skippedDays]);
        $this->assertGreaterThan(0, $seen[0]->hours);
        $this->assertSame('2026-03-09 00:00:00', $seen[0]->from->toDateTimeString());
        $this->assertSame('2026-03-10 12:30:00', $seen[0]->until->toDateTimeString());
    }

    public function test_the_rollup_command_dispatches_the_event(): void
    {
        Event::fake([RollupCompleted::class]);

        Artisan::call('spa-analytics:rollup');

        Event::assertDispatchedTimes(RollupCompleted::class, 1);
    }

    public function test_a_live_stats_call_dispatches_nothing(): void
    {
        AnalyticsEvent::factory()->create(['visitor_id' => 'v1', 'occurred_at' => Carbon::parse('2026-03-10 12:10:00')]);
        Event::fake([RollupCompleted::class]);

        Stats::between(Carbon::parse('2026-03-10'), Carbon::parse('2026-03-11'))->summary();

        Event::assertNotDispatched(RollupCompleted::class);
    }

    public function test_a_throwing_listener_never_fails_a_finished_run(): void
    {
        Log::spy();
        Event::listen(RollupCompleted::class, function (): void {
            throw new \RuntimeException('secret 203.0.113.9');
        });

        $counts = app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-09 00:00:00')->toImmutable());

        $this->assertGreaterThan(0, $counts['hours']);
        Log::shouldHaveReceived('warning')->with('spa-analytics: rollup listener failed', ['exception' => \RuntimeException::class, 'code' => 0])->once();
    }
}
