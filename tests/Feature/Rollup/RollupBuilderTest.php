<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupBuilder;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class RollupBuilderTest extends TestCase
{
    private function hour(string $time = '10:00:00'): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-03-02 '.$time);
    }

    private function rollup(RollupPeriod $period, CarbonImmutable $start): void
    {
        app(RollupBuilder::class)->rollup($period, $start);
    }

    private function row(RollupDimension $dimension, string $value = '', RollupPeriod $period = RollupPeriod::Hour, string $start = '10:00:00'): ?AnalyticsRollup
    {
        return AnalyticsRollup::query()
            ->where('period', $period)
            ->where('bucket_start', $this->hour($start))
            ->where('dimension', $dimension)
            ->where('value_hash', sha1($value))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pageView(string $at, string $visitor = 'v1', string $path = '/a', ?AnalyticsSession $session = null, array $attributes = []): AnalyticsEvent
    {
        return AnalyticsEvent::factory()->create([
            'visitor_id' => $visitor,
            'path' => $path,
            'occurred_at' => Carbon::parse('2026-03-02 '.$at),
            'session_id' => $session?->id,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function visit(string $start, string $end, array $attributes = []): AnalyticsSession
    {
        return AnalyticsSession::factory()->create([
            'started_at' => Carbon::parse('2026-03-02 '.$start),
            'last_seen_at' => Carbon::parse('2026-03-02 '.$end),
            ...$attributes,
        ]);
    }

    public function test_the_total_row_counts_page_views_and_distinct_visitors(): void
    {
        $this->pageView('10:05:00', 'v1');
        $this->pageView('10:10:00', 'v1');
        $this->pageView('10:20:00', 'v2');

        $this->rollup(RollupPeriod::Hour, $this->hour());

        $total = $this->row(RollupDimension::Total);
        $this->assertSame(3, $total->page_views);
        $this->assertSame(2, $total->visitors);
    }

    public function test_bots_and_custom_events_are_not_page_views(): void
    {
        $this->pageView('10:05:00', 'v1');
        $this->pageView('10:06:00', 'bot', attributes: ['is_bot' => true]);
        AnalyticsEvent::factory()->custom()->create(['visitor_id' => 'v3', 'occurred_at' => Carbon::parse('2026-03-02 10:07:00')]);

        $this->rollup(RollupPeriod::Hour, $this->hour());

        $this->assertSame(1, $this->row(RollupDimension::Total)->page_views);
        $this->assertSame(1, $this->row(RollupDimension::Total)->visitors);
    }

    public function test_buckets_are_half_open(): void
    {
        $this->pageView('09:59:59', 'before');
        $this->pageView('10:00:00', 'start');
        $this->pageView('10:59:59', 'end');
        $this->pageView('11:00:00', 'next');

        $this->rollup(RollupPeriod::Hour, $this->hour('10:00:00'));
        $this->rollup(RollupPeriod::Hour, $this->hour('11:00:00'));

        $this->assertSame(2, $this->row(RollupDimension::Total, start: '10:00:00')->page_views);
        $this->assertSame(1, $this->row(RollupDimension::Total, start: '11:00:00')->page_views);
    }

    public function test_a_bucket_without_traffic_still_gets_a_zero_total_row(): void
    {
        $this->rollup(RollupPeriod::Hour, $this->hour());

        $this->assertSame(1, AnalyticsRollup::query()->count());
        $total = $this->row(RollupDimension::Total);
        $this->assertSame(0, $total->page_views);
        $this->assertSame(0, $total->sessions);
    }

    public function test_paths_get_their_own_rows(): void
    {
        $this->pageView('10:05:00', 'v1', '/pricing');
        $this->pageView('10:06:00', 'v2', '/pricing');
        $this->pageView('10:07:00', 'v1', '/about');

        $this->rollup(RollupPeriod::Hour, $this->hour());

        $this->assertSame(2, $this->row(RollupDimension::Path, '/pricing')->page_views);
        $this->assertSame(2, $this->row(RollupDimension::Path, '/pricing')->visitors);
        $this->assertSame(1, $this->row(RollupDimension::Path, '/about')->page_views);
    }

    public function test_session_attributes_become_dimensions_for_page_views(): void
    {
        $session = $this->visit('10:00:00', '10:20:00', [
            'referrer_type' => ReferrerType::Search,
            'referrer_host' => 'google.com',
            'utm_campaign' => 'spring',
            'device_type' => DeviceType::Mobile,
            'os' => 'Android',
            'browser' => 'Chrome',
            'country' => 'BD',
        ]);
        $this->pageView('10:05:00', 'v1', session: $session);
        $this->pageView('10:10:00', 'v1', session: $session);

        $this->rollup(RollupPeriod::Hour, $this->hour());

        foreach ([
            [RollupDimension::ReferrerType, 'search'],
            [RollupDimension::ReferrerHost, 'google.com'],
            [RollupDimension::UtmCampaign, 'spring'],
            [RollupDimension::DeviceType, 'mobile'],
            [RollupDimension::Os, 'Android'],
            [RollupDimension::Browser, 'Chrome'],
            [RollupDimension::Country, 'BD'],
        ] as [$dimension, $value]) {
            $row = $this->row($dimension, $value);
            $this->assertSame(2, $row->page_views, $dimension->value);
            $this->assertSame(1, $row->visitors, $dimension->value);
        }
    }

    public function test_null_session_attributes_get_no_row(): void
    {
        $session = $this->visit('10:00:00', '10:20:00', ['utm_campaign' => null, 'country' => null]);
        $this->pageView('10:05:00', session: $session);

        $this->rollup(RollupPeriod::Hour, $this->hour());

        $this->assertSame(0, AnalyticsRollup::query()->where('dimension', RollupDimension::UtmCampaign)->count());
        $this->assertSame(0, AnalyticsRollup::query()->where('dimension', RollupDimension::Country)->count());
    }

    public function test_new_and_returning_visitors_are_split_by_the_session_flag(): void
    {
        $new = $this->visit('10:00:00', '10:10:00', ['is_new_visitor' => true]);
        $returning = $this->visit('10:00:00', '10:10:00', ['is_new_visitor' => false]);
        $this->pageView('10:05:00', 'v1', session: $new);
        $this->pageView('10:06:00', 'v2', session: $returning);
        $this->pageView('10:07:00', 'v2', session: $returning);

        $this->rollup(RollupPeriod::Hour, $this->hour());

        $this->assertSame(1, $this->row(RollupDimension::VisitorType, 'new')->page_views);
        $this->assertSame(2, $this->row(RollupDimension::VisitorType, 'returning')->page_views);
        $this->assertSame(1, $this->row(RollupDimension::VisitorType, 'new')->sessions);
        $this->assertSame(1, $this->row(RollupDimension::VisitorType, 'returning')->sessions);
    }

    public function test_sessions_bounces_and_duration_count_sessions_that_started_in_the_bucket(): void
    {
        $this->visit('10:00:00', '10:00:00', ['page_views' => 1]);
        $this->visit('10:10:00', '10:40:00', ['page_views' => 4]);
        $this->visit('09:50:00', '10:05:00', ['page_views' => 2]);
        $this->visit('10:30:00', '10:31:00', ['page_views' => 1, 'is_bot' => true]);

        $this->rollup(RollupPeriod::Hour, $this->hour());

        $total = $this->row(RollupDimension::Total);
        $this->assertSame(2, $total->sessions);
        $this->assertSame(1, $total->bounces);
        $this->assertSame(1800, $total->duration_seconds);
    }

    public function test_the_path_dimension_counts_sessions_by_entry_path(): void
    {
        $this->visit('10:00:00', '10:10:00', ['entry_path' => '/pricing', 'exit_path' => '/about', 'page_views' => 2]);
        $this->visit('10:20:00', '10:20:00', ['entry_path' => '/pricing', 'exit_path' => '/pricing', 'page_views' => 1]);

        $this->rollup(RollupPeriod::Hour, $this->hour());

        $row = $this->row(RollupDimension::Path, '/pricing');
        $this->assertSame(2, $row->sessions);
        $this->assertSame(1, $row->bounces);
        $this->assertSame(600, $row->duration_seconds);
    }

    public function test_custom_events_and_goals_get_their_own_dimensions(): void
    {
        AnalyticsEvent::factory()->custom()->create(['visitor_id' => 'v1', 'name' => 'signup_clicked', 'occurred_at' => Carbon::parse('2026-03-02 10:05:00')]);
        AnalyticsEvent::factory()->custom()->create(['visitor_id' => 'v1', 'name' => 'signup_clicked', 'occurred_at' => Carbon::parse('2026-03-02 10:06:00')]);
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'v2', 'name' => 'purchase', 'value' => 40, 'occurred_at' => Carbon::parse('2026-03-02 10:07:00')]);
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'v3', 'name' => 'purchase', 'value' => 9.5, 'occurred_at' => Carbon::parse('2026-03-02 10:08:00')]);
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'bot', 'name' => 'purchase', 'value' => 100, 'is_bot' => true, 'occurred_at' => Carbon::parse('2026-03-02 10:09:00')]);

        $this->rollup(RollupPeriod::Hour, $this->hour());

        $event = $this->row(RollupDimension::Event, 'signup_clicked');
        $this->assertSame(2, $event->events);
        $this->assertSame(1, $event->visitors);
        $this->assertSame('0.00', $event->revenue);

        $goal = $this->row(RollupDimension::Goal, 'purchase');
        $this->assertSame(2, $goal->events);
        $this->assertSame(2, $goal->visitors);
        $this->assertSame('49.50', $goal->revenue);

        $total = $this->row(RollupDimension::Total);
        $this->assertSame(4, $total->events);
        $this->assertSame('49.50', $total->revenue);
        $this->assertSame(0, $total->page_views);
    }

    public function test_a_day_counts_a_visitor_once_across_its_hours(): void
    {
        $this->pageView('10:05:00', 'v1');
        $this->pageView('15:05:00', 'v1');
        $this->pageView('15:10:00', 'v2');

        $this->rollup(RollupPeriod::Day, $this->hour('00:00:00'));

        $total = $this->row(RollupDimension::Total, period: RollupPeriod::Day, start: '00:00:00');
        $this->assertSame(3, $total->page_views);
        $this->assertSame(2, $total->visitors);
    }

    public function test_running_twice_gives_the_same_rows(): void
    {
        $this->pageView('10:05:00', 'v1', '/a');
        $this->pageView('10:06:00', 'v2', '/b');

        $this->rollup(RollupPeriod::Hour, $this->hour());
        $first = AnalyticsRollup::query()->orderBy('dimension')->orderBy('value')->get(['dimension', 'value', 'page_views', 'visitors'])->toArray();

        $this->rollup(RollupPeriod::Hour, $this->hour());
        $second = AnalyticsRollup::query()->orderBy('dimension')->orderBy('value')->get(['dimension', 'value', 'page_views', 'visitors'])->toArray();

        $this->assertSame($first, $second);
    }

    public function test_a_late_write_is_picked_up_and_vanished_values_are_removed(): void
    {
        $gone = $this->pageView('10:05:00', 'v1', '/old');
        $this->rollup(RollupPeriod::Hour, $this->hour());
        $this->assertNotNull($this->row(RollupDimension::Path, '/old'));

        $gone->delete();
        $this->pageView('10:30:00', 'v2', '/new');
        $this->rollup(RollupPeriod::Hour, $this->hour());

        $this->assertNull($this->row(RollupDimension::Path, '/old'));
        $this->assertSame(1, $this->row(RollupDimension::Path, '/new')->page_views);
        $this->assertSame(1, $this->row(RollupDimension::Total)->page_views);
    }

    public function test_other_buckets_and_periods_are_left_alone(): void
    {
        AnalyticsRollup::factory()->create(['period' => RollupPeriod::Hour, 'bucket_start' => $this->hour('09:00:00'), 'page_views' => 7]);
        AnalyticsRollup::factory()->create(['period' => RollupPeriod::Day, 'bucket_start' => $this->hour('00:00:00'), 'page_views' => 9]);

        $this->rollup(RollupPeriod::Hour, $this->hour('10:00:00'));

        $this->assertSame(7, $this->row(RollupDimension::Total, start: '09:00:00')->page_views);
        $this->assertSame(9, $this->row(RollupDimension::Total, period: RollupPeriod::Day, start: '00:00:00')->page_views);
    }

    public function test_hundreds_of_distinct_paths_are_all_written(): void
    {
        foreach (range(1, 300) as $i) {
            $this->pageView('10:05:00', 'v'.$i, '/page-'.$i);
        }

        $this->rollup(RollupPeriod::Hour, $this->hour());

        $this->assertSame(300, AnalyticsRollup::query()->where('dimension', RollupDimension::Path)->count());
    }

    public function test_values_that_differ_only_in_case_stay_separate(): void
    {
        $upper = $this->visit('10:00:00', '10:05:00', ['entry_path' => '/About', 'utm_campaign' => 'Spring', 'page_views' => 1]);
        $lower = $this->visit('10:10:00', '10:15:00', ['entry_path' => '/about', 'utm_campaign' => 'spring', 'page_views' => 1]);
        $this->pageView('10:01:00', 'v1', '/About', $upper);
        $this->pageView('10:11:00', 'v2', '/about', $lower);
        $this->pageView('10:12:00', 'v3', '/about', $lower);
        AnalyticsEvent::factory()->custom()->create(['visitor_id' => 'v1', 'name' => 'Signup', 'occurred_at' => Carbon::parse('2026-03-02 10:20:00')]);
        AnalyticsEvent::factory()->custom()->create(['visitor_id' => 'v2', 'name' => 'signup', 'occurred_at' => Carbon::parse('2026-03-02 10:21:00')]);
        AnalyticsEvent::factory()->custom()->create(['visitor_id' => 'v3', 'name' => 'signup', 'occurred_at' => Carbon::parse('2026-03-02 10:22:00')]);

        $this->rollup(RollupPeriod::Hour, $this->hour());

        $this->assertSame(1, $this->row(RollupDimension::Path, '/About')->page_views);
        $this->assertSame(2, $this->row(RollupDimension::Path, '/about')->page_views);
        $this->assertSame(1, $this->row(RollupDimension::Path, '/About')->sessions);
        $this->assertSame(1, $this->row(RollupDimension::Path, '/about')->sessions);
        $this->assertSame(1, $this->row(RollupDimension::UtmCampaign, 'Spring')->page_views);
        $this->assertSame(2, $this->row(RollupDimension::UtmCampaign, 'spring')->page_views);
        $this->assertSame(1, $this->row(RollupDimension::Event, 'Signup')->events);
        $this->assertSame(2, $this->row(RollupDimension::Event, 'signup')->events);
    }

    public function test_values_that_differ_only_in_trailing_spaces_stay_separate(): void
    {
        $this->pageView('10:01:00', 'v1', '/a');
        $this->pageView('10:02:00', 'v2', '/a ');

        $this->rollup(RollupPeriod::Hour, $this->hour());

        $this->assertSame(2, AnalyticsRollup::query()->where('dimension', RollupDimension::Path)->count());
    }
}
