<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsState;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class PrunedThroughTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 14:20:00');
        config()->set('spa-analytics.retention_days', 3);
    }

    private function rolledDay(string $day, int $pageViews = 99): void
    {
        AnalyticsRollup::factory()->create([
            'period' => RollupPeriod::Day,
            'bucket_start' => Carbon::parse($day),
            'dimension' => RollupDimension::Total,
            'value' => '',
            'page_views' => $pageViews,
        ]);
    }

    private function pageView(string $at): AnalyticsEvent
    {
        return AnalyticsEvent::factory()->create(['occurred_at' => Carbon::parse($at)]);
    }

    private function goal(string $at): AnalyticsEvent
    {
        return AnalyticsEvent::factory()->goal()->create(['occurred_at' => Carbon::parse($at)]);
    }

    private function markedThrough(): ?string
    {
        return AnalyticsState::query()->find(AnalyticsState::PRUNED_THROUGH)?->value;
    }

    private function dailyPageViews(string $day): ?int
    {
        return AnalyticsRollup::query()
            ->where('period', RollupPeriod::Day)
            ->where('dimension', RollupDimension::Total)
            ->where('bucket_start', Carbon::parse($day))
            ->value('page_views');
    }

    public function test_a_prune_records_the_moment_it_deleted_up_to(): void
    {
        $this->rolledDay('2026-03-05');
        $this->rolledDay('2026-03-06');
        $this->pageView('2026-03-05 10:00:00');

        $this->artisan('spa-analytics:prune')->assertExitCode(0);

        $this->assertSame('2026-03-07 00:00:00', $this->markedThrough());
    }

    public function test_a_blocked_prune_records_nothing(): void
    {
        $this->pageView('2026-03-05 10:00:00');

        $this->artisan('spa-analytics:prune')->assertExitCode(1);

        $this->assertNull($this->markedThrough());
    }

    public function test_a_later_prune_moves_an_existing_marker_forward_and_deletes_the_new_days(): void
    {
        AnalyticsState::factory()->prunedThrough('2026-03-05 00:00:00')->create();
        $this->rolledDay('2026-03-05');
        $this->rolledDay('2026-03-06');
        $this->pageView('2026-03-05 10:00:00');
        $this->pageView('2026-03-06 10:00:00');
        $kept = $this->pageView('2026-03-08 10:00:00');

        $this->artisan('spa-analytics:prune')->assertExitCode(0);

        $this->assertSame('2026-03-07 00:00:00', $this->markedThrough());
        $this->assertSame([$kept->id], AnalyticsEvent::query()->pluck('id')->all());
    }

    public function test_a_prune_that_cannot_go_further_leaves_the_marker_where_it_is(): void
    {
        AnalyticsState::factory()->prunedThrough('2026-03-07 00:00:00')->create();
        $this->rolledDay('2026-03-05');
        $this->pageView('2026-03-05 10:00:00');

        $this->artisan('spa-analytics:prune')->assertExitCode(0);

        $this->assertSame('2026-03-07 00:00:00', $this->markedThrough());
    }

    public function test_a_late_row_before_the_marker_is_deleted_and_does_not_block_the_prune(): void
    {
        AnalyticsState::factory()->prunedThrough('2026-03-07 00:00:00')->create();
        $this->rolledDay('2026-03-05');
        $this->rolledDay('2026-03-06');
        $late = $this->goal('2026-03-05 10:00:00');
        $kept = $this->pageView('2026-03-08 10:00:00');

        $this->artisan('spa-analytics:prune')->assertExitCode(0);

        $this->assertFalse(AnalyticsEvent::query()->whereKey($late->id)->exists());
        $this->assertTrue(AnalyticsEvent::query()->whereKey($kept->id)->exists());
        $this->assertSame('2026-03-07 00:00:00', $this->markedThrough());
    }

    public function test_an_install_without_a_marker_prunes_a_day_whose_raw_rows_are_gone_even_with_a_late_goal(): void
    {
        $this->rolledDay('2026-03-05');
        $this->rolledDay('2026-03-06');
        $late = $this->goal('2026-03-05 10:00:00');

        $this->artisan('spa-analytics:prune')->assertExitCode(0);

        $this->assertFalse(AnalyticsEvent::query()->whereKey($late->id)->exists());
        $this->assertSame('2026-03-07 00:00:00', $this->markedThrough());
    }

    public function test_the_scheduled_run_does_not_rebuild_a_marked_day(): void
    {
        AnalyticsState::factory()->prunedThrough('2026-03-07 00:00:00')->create();
        $this->pageView('2026-03-05 10:00:00');

        $this->artisan('spa-analytics:rollup')->assertExitCode(0);

        $this->assertNull($this->dailyPageViews('2026-03-05'));
    }

    public function test_an_explicit_since_does_not_rebuild_a_marked_day(): void
    {
        AnalyticsState::factory()->prunedThrough('2026-03-07 00:00:00')->create();
        $this->pageView('2026-03-05 10:00:00');

        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-05'])->assertExitCode(0);

        $this->assertNull($this->dailyPageViews('2026-03-05'));
    }

    public function test_the_scheduled_run_does_not_shrink_a_pruned_day_that_a_late_goal_made_look_behind(): void
    {
        $this->rolledDay('2026-03-05');
        $this->goal('2026-03-05 10:00:00');

        $this->artisan('spa-analytics:rollup')->assertExitCode(0);

        $this->assertSame(99, $this->dailyPageViews('2026-03-05'));
    }

    public function test_a_day_after_the_marker_is_still_rebuilt_when_it_is_behind(): void
    {
        AnalyticsState::factory()->prunedThrough('2026-03-05 00:00:00')->create();
        $this->rolledDay('2026-03-06', 0);
        $this->pageView('2026-03-06 10:00:00');

        $this->artisan('spa-analytics:rollup')->assertExitCode(0);

        $this->assertSame(1, $this->dailyPageViews('2026-03-06'));
    }
}
