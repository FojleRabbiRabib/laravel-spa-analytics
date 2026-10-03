<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\FunnelStep;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Query\FunnelCounter;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Query\RangePlanner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FunnelChunkingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function pageView(string $visitor, string $time, string $path): void
    {
        AnalyticsEvent::factory()->create(['visitor_id' => $visitor, 'occurred_at' => Carbon::parse($time), 'path' => $path]);
    }

    private function goal(string $visitor, string $time, string $name): void
    {
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => $visitor, 'name' => $name, 'occurred_at' => Carbon::parse($time)]);
    }

    /**
     * @param  array<int, FunnelStep>  $steps
     * @return array<int, int>
     */
    private function funnel(int $chunk, array $steps): array
    {
        $plan = app(RangePlanner::class)->plan(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'), Carbon::parse('2026-03-10 12:00:00'));

        return (new FunnelCounter($chunk))->count($plan, $steps, $plan->from);
    }

    private function seedVisitors(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $visitor) {
            $this->pageView($visitor, '2026-03-08 10:00:00', '/pricing');
        }

        foreach (['a', 'c', 'e'] as $visitor) {
            $this->pageView($visitor, '2026-03-08 11:00:00', '/checkout');
        }

        $this->goal('a', '2026-03-08 12:00:00', 'purchase');
        $this->goal('e', '2026-03-08 12:00:00', 'purchase');
        $this->goal('x-goal-only', '2026-03-08 12:00:00', 'purchase');

        foreach (range(1, 12) as $minute) {
            $this->pageView('busy', '2026-03-09 10:'.str_pad((string) $minute, 2, '0', STR_PAD_LEFT).':00', '/pricing');
        }

        $this->pageView('busy', '2026-03-09 11:00:00', '/checkout');
        $this->goal('busy', '2026-03-09 12:00:00', 'purchase');
    }

    public function test_every_chunk_size_gives_the_same_counts_even_for_a_visitor_with_more_events_than_the_chunk(): void
    {
        $this->seedVisitors();
        $steps = [FunnelStep::path('/pricing'), FunnelStep::path('/checkout'), FunnelStep::goal('purchase')];

        $this->assertSame([6, 4, 3], $this->funnel(500, $steps));
        $this->assertSame([6, 4, 3], $this->funnel(1, $steps));
        $this->assertSame([6, 4, 3], $this->funnel(2, $steps));
        $this->assertSame([6, 4, 3], $this->funnel(5, $steps));
    }

    public function test_the_events_are_read_in_chunks_of_visitors_not_in_one_go(): void
    {
        $this->seedVisitors();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->funnel(2, [FunnelStep::path('/pricing')]);

        $eventReads = array_filter($queries, fn (string $sql): bool => preg_match('/\bvisitor_id\W*\s+in\s*\(/i', $sql) === 1);

        $this->assertGreaterThan(1, count($eventReads));
    }

    public function test_visitors_that_differ_only_in_case_stay_apart_across_chunk_boundaries(): void
    {
        $this->pageView('Bob', '2026-03-08 10:00:00', '/Pricing');
        $this->goal('Bob', '2026-03-08 10:05:00', 'signup');
        $this->pageView('bob', '2026-03-08 10:00:00', '/pricing');
        $this->pageView('carol', '2026-03-08 10:00:00', '/Pricing');

        $steps = [FunnelStep::path('/Pricing'), FunnelStep::goal('signup')];

        $this->assertSame([2, 1], $this->funnel(1, $steps));
        $this->assertSame([2, 1], $this->funnel(500, $steps));
    }

    public function test_an_empty_range_gives_zero_counts_and_stops(): void
    {
        $this->assertSame([0, 0], $this->funnel(1, [FunnelStep::path('/pricing'), FunnelStep::goal('signup')]));
    }
}
