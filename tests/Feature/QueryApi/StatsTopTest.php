<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsTopTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    /**
     * A session with one page view per time => path pair.
     *
     * @param  array<string, string>  $views
     * @param  array<string, mixed>  $attributes
     */
    private function visit(string $visitor, array $views, array $attributes = []): AnalyticsSession
    {
        ksort($views);
        $times = array_keys($views);

        $session = AnalyticsSession::factory()->create([
            'visitor_id' => $visitor,
            'started_at' => Carbon::parse($times[0]),
            'last_seen_at' => Carbon::parse(end($times)),
            'page_views' => count($views),
            'entry_path' => reset($views),
            'exit_path' => end($views),
            'is_new_visitor' => true,
            ...$attributes,
        ]);

        foreach ($views as $time => $path) {
            AnalyticsEvent::factory()->create([
                'visitor_id' => $visitor,
                'session_id' => $session->id,
                'occurred_at' => Carbon::parse($time),
                'path' => $path,
            ]);
        }

        return $session;
    }

    private function rollUp(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08 00:00:00')->toImmutable());
    }

    private function report()
    {
        return Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'));
    }

    public function test_paths_are_ranked_by_page_views_with_exact_users(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00' => '/pricing', '2026-03-08 10:05:00' => '/docs']);
        $this->visit('v1', ['2026-03-09 10:00:00' => '/pricing'], ['is_new_visitor' => false]);
        $this->visit('v2', ['2026-03-09 11:00:00' => '/pricing']);
        $this->visit('v3', ['2026-03-09 12:00:00' => '/docs']);
        $this->rollUp();

        $rows = $this->report()->top(RollupDimension::Path);

        $this->assertSame(['/pricing', '/docs'], array_map(fn ($row) => $row->value, $rows));
        $this->assertSame(3, $rows[0]->pageViews);
        $this->assertSame(2, $rows[0]->users);
        $this->assertSame(2, $rows[1]->pageViews);
        $this->assertSame(2, $rows[1]->users);
        $this->assertTrue($rows[0]->usersExact);
    }

    public function test_values_that_differ_only_in_case_stay_separate_rows(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00' => '/About']);
        $this->visit('v2', ['2026-03-08 11:00:00' => '/about']);
        $this->visit('v3', ['2026-03-08 12:00:00' => '/about']);
        $this->rollUp();

        $rows = $this->report()->top(RollupDimension::Path);

        $this->assertSame(['/about', '/About'], array_map(fn ($row) => $row->value, $rows));
        $this->assertSame([2, 1], array_map(fn ($row) => $row->users, $rows));
    }

    public function test_the_limit_applies_after_ranking(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00' => '/a']);
        $this->visit('v2', ['2026-03-08 11:00:00' => '/b']);
        $this->visit('v3', ['2026-03-08 12:00:00' => '/b']);
        $this->rollUp();

        $rows = $this->report()->top(RollupDimension::Path, 1);

        $this->assertCount(1, $rows);
        $this->assertSame('/b', $rows[0]->value);
    }

    public function test_exit_paths_are_ranked_by_sessions_with_users_from_sessions(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00' => '/a', '2026-03-08 10:05:00' => '/checkout']);
        $this->visit('v2', ['2026-03-08 11:00:00' => '/checkout']);
        $this->visit('v3', ['2026-03-08 12:00:00' => '/a']);
        $this->rollUp();

        $rows = $this->report()->top(RollupDimension::ExitPath);

        $this->assertSame(['/checkout', '/a'], array_map(fn ($row) => $row->value, $rows));
        $this->assertSame(2, $rows[0]->sessions);
        $this->assertSame(2, $rows[0]->users);
        $this->assertTrue($rows[0]->usersExact);
    }

    public function test_session_dimensions_rank_by_sessions(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00' => '/a'], ['device_type' => DeviceType::Mobile]);
        $this->visit('v2', ['2026-03-08 11:00:00' => '/a'], ['device_type' => DeviceType::Desktop]);
        $this->visit('v3', ['2026-03-08 12:00:00' => '/a'], ['device_type' => DeviceType::Desktop]);
        $this->rollUp();

        $rows = $this->report()->top(RollupDimension::DeviceType);

        $this->assertSame(['desktop', 'mobile'], array_map(fn ($row) => $row->value, $rows));
        $this->assertSame(2, $rows[0]->users);
    }

    public function test_visitor_type_rows_count_new_and_returning_people(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00' => '/a'], ['is_new_visitor' => false]);
        $this->visit('v2', ['2026-03-08 11:00:00' => '/a']);
        $this->visit('v3', ['2026-03-08 12:00:00' => '/a']);
        $this->rollUp();

        $rows = $this->report()->top(RollupDimension::VisitorType);

        $this->assertSame(['new' => 2, 'returning' => 1], collect($rows)->mapWithKeys(fn ($row) => [$row->value => $row->users])->all());
    }

    public function test_events_and_goals_are_ranked_by_events_with_revenue(): void
    {
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'v1', 'name' => 'purchase', 'value' => 40, 'occurred_at' => Carbon::parse('2026-03-08 10:00:00')]);
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'v1', 'name' => 'purchase', 'value' => 10, 'occurred_at' => Carbon::parse('2026-03-08 11:00:00')]);
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'v2', 'name' => 'signup', 'occurred_at' => Carbon::parse('2026-03-08 12:00:00')]);
        AnalyticsEvent::factory()->custom()->create(['visitor_id' => 'v3', 'name' => 'clicked', 'occurred_at' => Carbon::parse('2026-03-08 12:00:00')]);
        $this->rollUp();

        $goals = $this->report()->top(RollupDimension::Goal);
        $events = $this->report()->top(RollupDimension::Event);

        $this->assertSame(['purchase', 'signup'], array_map(fn ($row) => $row->value, $goals));
        $this->assertSame(2, $goals[0]->events);
        $this->assertSame(1, $goals[0]->users);
        $this->assertSame('50.00', $goals[0]->revenue);
        $this->assertSame(['clicked'], array_map(fn ($row) => $row->value, $events));
    }

    public function test_pruned_days_make_a_row_inexact_and_add_the_daily_users(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00' => '/a']);
        $this->visit('v2', ['2026-03-08 11:00:00' => '/a']);
        $this->visit('v1', ['2026-03-09 10:00:00' => '/a'], ['is_new_visitor' => false]);
        $this->rollUp();
        AnalyticsEvent::query()->where('occurred_at', '<', Carbon::parse('2026-03-09'))->delete();
        AnalyticsSession::query()->where('started_at', '<', Carbon::parse('2026-03-09'))->delete();

        $rows = $this->report()->top(RollupDimension::Path);

        $this->assertSame(3, $rows[0]->pageViews);
        $this->assertSame(3, $rows[0]->users);
        $this->assertFalse($rows[0]->usersExact);
    }

    public function test_utm_dimensions_rank_by_sessions_with_exact_users_and_keep_case_variants_apart(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00' => '/a'], ['utm_source' => 'newsletter', 'utm_medium' => 'email']);
        $this->visit('v2', ['2026-03-08 11:00:00' => '/a'], ['utm_source' => 'newsletter', 'utm_medium' => 'email']);
        $this->visit('v1', ['2026-03-09 10:00:00' => '/a'], ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'is_new_visitor' => false]);
        $this->visit('v3', ['2026-03-09 11:00:00' => '/a'], ['utm_source' => 'Newsletter']);
        $this->rollUp();

        $sources = $this->report()->top(RollupDimension::UtmSource);
        $mediums = $this->report()->top(RollupDimension::UtmMedium);

        $this->assertSame(['newsletter', 'Newsletter'], array_map(fn ($row) => $row->value, $sources));
        $this->assertSame([3, 1], array_map(fn ($row) => $row->sessions, $sources));
        $this->assertSame([2, 1], array_map(fn ($row) => $row->users, $sources));
        $this->assertSame(['email'], array_map(fn ($row) => $row->value, $mediums));
        $this->assertSame(2, $mediums[0]->users);
    }

    public function test_pruned_days_make_a_utm_row_inexact(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00' => '/a'], ['utm_term' => 'running shoes']);
        $this->visit('v2', ['2026-03-09 10:00:00' => '/a'], ['utm_term' => 'running shoes']);
        $this->rollUp();
        AnalyticsEvent::query()->where('occurred_at', '<', Carbon::parse('2026-03-09'))->delete();
        AnalyticsSession::query()->where('started_at', '<', Carbon::parse('2026-03-09'))->delete();

        $rows = $this->report()->top(RollupDimension::UtmTerm);

        $this->assertSame(2, $rows[0]->users);
        $this->assertFalse($rows[0]->usersExact);
    }

    public function test_the_total_dimension_cannot_be_ranked_and_no_rollups_give_no_rows(): void
    {
        $this->assertSame([], $this->report()->top(RollupDimension::Path));

        $this->expectException(\InvalidArgumentException::class);

        $this->report()->top(RollupDimension::Total);
    }

    public function test_rows_serialise_to_json_friendly_values(): void
    {
        $this->visit('v1', ['2026-03-08 10:00:00' => '/a']);
        $this->rollUp();

        $row = $this->report()->top(RollupDimension::Path)[0]->toArray();

        $this->assertSame(['value', 'pageViews', 'users', 'usersExact', 'sessions', 'bounces', 'bounceRate', 'avgSessionDuration', 'events', 'revenue'], array_keys($row));
        $this->assertSame('/a', $row['value']);
        $this->assertNotFalse(json_encode($row));
    }
}
