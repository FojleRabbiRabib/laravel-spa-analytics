<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\FunnelStep;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Analytics;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsFunnelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function pageView(string $visitor, string $time, string $path, bool $bot = false): void
    {
        AnalyticsEvent::factory()->create(['visitor_id' => $visitor, 'occurred_at' => Carbon::parse($time), 'path' => $path, 'is_bot' => $bot]);
    }

    private function goal(string $visitor, string $time, string $name): void
    {
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => $visitor, 'name' => $name, 'occurred_at' => Carbon::parse($time)]);
    }

    private function custom(string $visitor, string $time, string $name): void
    {
        AnalyticsEvent::factory()->custom()->create(['visitor_id' => $visitor, 'name' => $name, 'occurred_at' => Carbon::parse($time)]);
    }

    private function rollUp(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-01 00:00:00')->toImmutable());
    }

    private function report()
    {
        return Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'));
    }

    /**
     * @return array<int, int>
     */
    private function users(array $steps): array
    {
        return array_map(fn ($step) => $step->users, $this->report()->funnel($steps)->steps);
    }

    public function test_steps_count_in_order_even_days_apart(): void
    {
        $this->pageView('ordered', '2026-03-08 10:00:00', '/pricing');
        $this->goal('ordered', '2026-03-09 15:00:00', 'signup');
        $this->goal('backwards', '2026-03-08 10:00:00', 'signup');
        $this->pageView('backwards', '2026-03-08 11:00:00', '/pricing');
        $this->pageView('stalled', '2026-03-08 12:00:00', '/pricing');
        $this->rollUp();

        $funnel = $this->report()->funnel([FunnelStep::path('/pricing'), FunnelStep::goal('signup')]);

        $this->assertSame([3, 1], array_map(fn ($step) => $step->users, $funnel->steps));
        $this->assertSame(1 / 3, $funnel->steps[1]->fromPrevious);
        $this->assertSame(1 / 3, $funnel->conversionRate);
        $this->assertSame(1.0, $funnel->steps[0]->fromFirst);
        $this->assertTrue($funnel->complete);
    }

    public function test_a_repeated_step_needs_two_events(): void
    {
        $this->pageView('once', '2026-03-08 10:00:00', '/a');
        $this->pageView('twice', '2026-03-08 10:00:00', '/a');
        $this->pageView('twice', '2026-03-08 11:00:00', '/a');
        $this->rollUp();

        $this->assertSame([2, 1], $this->users([FunnelStep::path('/a'), FunnelStep::path('/a')]));
    }

    public function test_events_and_goals_with_the_same_name_are_different_steps(): void
    {
        $this->pageView('v1', '2026-03-08 10:00:00', '/a');
        $this->custom('v1', '2026-03-08 10:05:00', 'signup');
        $this->pageView('v2', '2026-03-08 10:00:00', '/a');
        $this->goal('v2', '2026-03-08 10:05:00', 'signup');
        $this->rollUp();

        $this->assertSame([2, 1], $this->users([FunnelStep::path('/a'), FunnelStep::goal('signup')]));
        $this->assertSame([2, 1], $this->users([FunnelStep::path('/a'), FunnelStep::event('signup')]));
    }

    public function test_paths_and_visitors_that_differ_only_in_case_stay_apart(): void
    {
        $this->pageView('Bob', '2026-03-08 10:00:00', '/Pricing');
        $this->goal('Bob', '2026-03-08 10:05:00', 'signup');
        $this->pageView('bob', '2026-03-08 10:00:00', '/pricing');
        $this->rollUp();

        $this->assertSame([1, 1], $this->users([FunnelStep::path('/Pricing'), FunnelStep::goal('signup')]));
        $this->assertSame([1, 0], $this->users([FunnelStep::path('/pricing'), FunnelStep::goal('signup')]));
    }

    public function test_a_prefix_step_matches_literally_not_as_a_pattern(): void
    {
        $this->pageView('literal', '2026-03-08 10:00:00', '/a_b/1');
        $this->pageView('other', '2026-03-08 10:00:00', '/axb/1');
        $this->pageView('percent', '2026-03-08 10:00:00', '/100%/x');
        $this->goal('literal', '2026-03-08 11:00:00', 'signup');
        $this->goal('other', '2026-03-08 11:00:00', 'signup');
        $this->rollUp();

        $this->assertSame([1, 1], $this->users([FunnelStep::pathStartingWith('/a_b'), FunnelStep::goal('signup')]));
        $this->assertSame([1, 0], $this->users([FunnelStep::pathStartingWith('/100%'), FunnelStep::goal('signup')]));
    }

    public function test_bots_and_the_running_hour_are_left_out(): void
    {
        $this->pageView('bot', '2026-03-08 10:00:00', '/a', true);
        $this->goal('bot', '2026-03-08 10:05:00', 'signup');
        $this->pageView('human', '2026-03-08 10:00:00', '/a');
        $this->rollUp();
        $this->goal('human', '2026-03-10 12:10:00', 'signup');

        $funnel = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-11'))->funnel([FunnelStep::path('/a'), FunnelStep::goal('signup')]);

        $this->assertSame([1, 0], array_map(fn ($step) => $step->users, $funnel->steps));
    }

    public function test_in_the_same_second_a_page_view_comes_before_a_goal_however_they_were_stored(): void
    {
        $this->pageView('v1', '2026-03-08 10:00:00', '/a');
        $this->goal('v1', '2026-03-08 10:00:00', 'signup');
        $this->goal('v2', '2026-03-08 10:00:00', 'signup');
        $this->pageView('v2', '2026-03-08 10:00:00', '/a');
        $this->goal('v3', '2026-03-08 09:59:59', 'signup');
        $this->pageView('v3', '2026-03-08 10:00:00', '/a');
        $this->rollUp();

        $this->assertSame([3, 2], $this->users([FunnelStep::path('/a'), FunnelStep::goal('signup')]));
    }

    public function test_a_goal_fired_while_rendering_a_page_counts_after_that_page_view(): void
    {
        Carbon::setTestNow('2026-03-08 10:00:00');

        $this->withCredentials()->withCookie('spa_analytics_vid', '11111111-1111-4111-8111-111111111111')
            ->withHeaders(['Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0 (X11; Linux) Firefox/121.0'])
            ->get('/thank-you')
            ->assertOk();

        $this->assertSame(2, AnalyticsEvent::query()->count());

        Carbon::setTestNow('2026-03-10 12:30:00');
        $this->rollUp();

        $this->assertSame([1, 1], $this->users([FunnelStep::path('/thank-you'), FunnelStep::goal('purchase')]));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.tracking.write_mode', 'sync');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/thank-you', function () {
            Analytics::goal('purchase');

            return 'ok';
        });
    }

    public function test_pruned_days_are_not_counted_and_the_funnel_says_where_it_starts(): void
    {
        $this->pageView('old', '2026-03-08 10:00:00', '/a');
        $this->goal('old', '2026-03-08 11:00:00', 'signup');
        $this->pageView('new', '2026-03-09 10:00:00', '/a');
        $this->goal('new', '2026-03-09 11:00:00', 'signup');
        $this->rollUp();
        AnalyticsEvent::query()->where('occurred_at', '<', Carbon::parse('2026-03-09'))->delete();

        $funnel = $this->report()->funnel([FunnelStep::path('/a'), FunnelStep::goal('signup')]);

        $this->assertSame([1, 1], array_map(fn ($step) => $step->users, $funnel->steps));
        $this->assertFalse($funnel->complete);
        $this->assertSame('2026-03-09 00:00:00', $funnel->coveredFrom?->toDateTimeString());
    }

    public function test_an_empty_stretch_before_the_first_raw_event_does_not_make_the_funnel_partial(): void
    {
        $this->pageView('v1', '2026-03-09 10:00:00', '/a');
        $this->goal('v1', '2026-03-09 11:00:00', 'signup');
        $this->rollUp();

        $funnel = Stats::between(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-10'))->funnel([FunnelStep::path('/a'), FunnelStep::goal('signup')]);

        $this->assertTrue($funnel->complete);
        $this->assertSame('2026-03-01 00:00:00', $funnel->coveredFrom?->toDateTimeString());
        $this->assertSame([1, 1], array_map(fn ($step) => $step->users, $funnel->steps));
    }

    public function test_the_first_step_agrees_with_the_top_list_when_nothing_is_pruned(): void
    {
        $this->pageView('v1', '2026-03-08 10:00:00', '/pricing');
        $this->pageView('v1', '2026-03-09 10:00:00', '/pricing');
        $this->pageView('v2', '2026-03-09 11:00:00', '/pricing');
        $this->pageView('v3', '2026-03-09 12:00:00', '/docs');
        $this->rollUp();

        $top = $this->report()->top(RollupDimension::Path);
        $pricing = collect($top)->firstWhere('value', '/pricing');

        $this->assertSame($pricing->users, $this->users([FunnelStep::path('/pricing'), FunnelStep::path('/docs')])[0]);
    }

    public function test_without_rollups_every_step_is_zero(): void
    {
        $this->pageView('v1', '2026-03-08 10:00:00', '/a');

        $funnel = $this->report()->funnel([FunnelStep::path('/a'), FunnelStep::goal('signup')]);

        $this->assertSame([0, 0], array_map(fn ($step) => $step->users, $funnel->steps));
        $this->assertSame(0.0, $funnel->conversionRate);
        $this->assertNull($funnel->through);
        $this->assertNull($funnel->coveredFrom);
    }

    public function test_the_step_list_is_validated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->report()->funnel([FunnelStep::path('/a')]);
    }

    public function test_too_many_steps_and_empty_values_are_rejected(): void
    {
        try {
            $this->report()->funnel(array_fill(0, 11, FunnelStep::path('/a')));
            $this->fail('Eleven steps should be rejected.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);

        FunnelStep::goal('');
    }

    public function test_the_funnel_serialises_with_a_fixed_shape(): void
    {
        $this->pageView('v1', '2026-03-08 10:00:00', '/a');
        $this->rollUp();

        $array = $this->report()->funnel([FunnelStep::path('/a', 'Landing'), FunnelStep::pathStartingWith('/b')])->toArray();

        $this->assertSame(['steps', 'conversionRate', 'coveredFrom', 'complete', 'through'], array_keys($array));
        $this->assertSame(['type', 'value', 'label', 'users', 'fromPrevious', 'fromFirst'], array_keys($array['steps'][0]));
        $this->assertSame('Landing', $array['steps'][0]['label']);
        $this->assertSame('/b*', $array['steps'][1]['label']);
        $this->assertNotFalse(json_encode($array));
    }
}
