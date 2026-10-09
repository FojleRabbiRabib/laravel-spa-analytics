<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsDisabledDimensionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
        AnalyticsEvent::factory()->create(['visitor_id' => 'v1', 'path' => '/a', 'occurred_at' => Carbon::parse('2026-03-08 10:00:00')]);
    }

    private function report()
    {
        return Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'));
    }

    public function test_top_refuses_a_disabled_dimension_and_names_the_config_key(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08')->toImmutable());
        config()->set('spa-analytics.rollups.disabled_dimensions', ['path']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path dimension is turned off in rollups.disabled_dimensions');

        $this->report()->top(RollupDimension::Path);
    }

    public function test_top_works_again_when_the_dimension_is_enabled_and_other_dimensions_are_unaffected(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08')->toImmutable());
        config()->set('spa-analytics.rollups.disabled_dimensions', ['utm_term']);

        $this->assertSame('/a', $this->report()->top(RollupDimension::Path)[0]->value);

        config()->set('spa-analytics.rollups.disabled_dimensions', ['path']);

        $this->assertThrows(fn () => $this->report()->top(RollupDimension::Path), \InvalidArgumentException::class);

        config()->set('spa-analytics.rollups.disabled_dimensions', []);

        $this->assertSame('/a', $this->report()->top(RollupDimension::Path)[0]->value);
    }

    public function test_the_headline_numbers_and_goals_do_not_depend_on_a_disabled_dimension(): void
    {
        config()->set('spa-analytics.rollups.disabled_dimensions', ['path', 'event', 'country', 'goal', 'visitor_type']);
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08')->toImmutable());

        $summary = $this->report()->summary();

        $this->assertSame(1, $summary->pageViews);
        $this->assertSame(1, $summary->users);
        $this->assertSame([], $this->report()->goals());
    }
}
