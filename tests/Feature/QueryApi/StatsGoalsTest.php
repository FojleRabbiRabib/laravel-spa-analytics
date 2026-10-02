<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsGoalsTest extends TestCase
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

        AnalyticsEvent::factory()->create(['visitor_id' => $visitor, 'session_id' => $session->id, 'occurred_at' => Carbon::parse($time)]);
    }

    private function goal(string $visitor, string $time, string $name, ?float $value = null): void
    {
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => $visitor, 'name' => $name, 'value' => $value, 'occurred_at' => Carbon::parse($time)]);
    }

    private function rollUp(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08 00:00:00')->toImmutable());
    }

    public function test_each_goal_has_completions_revenue_users_and_a_conversion_rate(): void
    {
        foreach (['v1', 'v2', 'v3', 'v4'] as $index => $visitor) {
            $this->visit($visitor, '2026-03-08 1'.$index.':00:00');
        }

        $this->goal('v1', '2026-03-08 10:05:00', 'purchase', 40);
        $this->goal('v1', '2026-03-08 10:06:00', 'purchase', 10);
        $this->goal('v2', '2026-03-08 11:05:00', 'purchase', 5);
        $this->goal('v3', '2026-03-08 12:05:00', 'signup');
        $this->rollUp();

        $goals = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-09'))->goals();

        $this->assertSame(['purchase', 'signup'], array_map(fn ($goal) => $goal->name, $goals));
        $this->assertSame(3, $goals[0]->completions);
        $this->assertSame(2, $goals[0]->users);
        $this->assertSame('55.00', $goals[0]->revenue);
        $this->assertSame(0.5, $goals[0]->conversionRate);
        $this->assertSame(0.25, $goals[1]->conversionRate);
        $this->assertTrue($goals[0]->usersExact);
    }

    public function test_goal_completers_without_a_page_view_do_not_count_towards_conversion(): void
    {
        $this->visit('v1', '2026-03-08 10:00:00');
        $this->goal('webhook-a', '2026-03-08 11:00:00', 'purchase');
        $this->goal('webhook-b', '2026-03-08 12:00:00', 'purchase');
        $this->rollUp();

        $report = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-09'));
        $goals = $report->goals();

        $this->assertSame(2, $goals[0]->users);
        $this->assertSame(0.0, $goals[0]->conversionRate);
        $this->assertSame(0.0, $report->summary()->conversionRate);
    }

    public function test_no_goal_converts_better_than_goals_overall(): void
    {
        $this->visit('v1', '2026-03-08 10:00:00');
        $this->visit('v2', '2026-03-08 11:00:00');
        $this->goal('v1', '2026-03-08 10:05:00', 'purchase');
        $this->goal('v2', '2026-03-08 11:05:00', 'signup');
        $this->goal('webhook', '2026-03-08 12:00:00', 'purchase');
        $this->rollUp();

        $report = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-09'));
        $overall = $report->summary()->conversionRate;

        $this->assertSame(1.0, $overall);

        foreach ($report->goals() as $goal) {
            $this->assertLessThanOrEqual($overall, $goal->conversionRate);
        }

        $this->assertSame(0.5, $report->goals()[0]->conversionRate);
    }

    public function test_no_goals_give_no_rows_and_rows_serialise_to_json(): void
    {
        $this->visit('v1', '2026-03-08 10:00:00');
        $this->rollUp();

        $report = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-09'));

        $this->assertSame([], $report->goals());

        $this->goal('v1', '2026-03-08 10:05:00', 'purchase');
        $this->rollUp();

        $this->assertSame(['name', 'completions', 'users', 'usersExact', 'revenue', 'conversionRate'], array_keys($report->goals()[0]->toArray()));
        $this->assertNotFalse(json_encode($report->goals()[0]->toArray()));
    }
}
