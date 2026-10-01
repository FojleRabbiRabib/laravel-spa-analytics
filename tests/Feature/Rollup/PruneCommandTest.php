<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RetentionPruner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

class PruneCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 14:20:00');
        config()->set('spa-analytics.retention_days', 3);
    }

    private function rolledDay(string $day): void
    {
        AnalyticsRollup::factory()->create([
            'period' => RollupPeriod::Day,
            'bucket_start' => Carbon::parse($day),
            'dimension' => RollupDimension::Total,
            'value' => '',
        ]);
    }

    private function pageView(string $at): AnalyticsEvent
    {
        return AnalyticsEvent::factory()->create(['occurred_at' => Carbon::parse($at)]);
    }

    private function visit(string $start, string $end): AnalyticsSession
    {
        return AnalyticsSession::factory()->create(['started_at' => Carbon::parse($start), 'last_seen_at' => Carbon::parse($end)]);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function noRetention(): array
    {
        return ['null' => [null], 'empty' => [''], 'zero' => [0], 'zero string' => ['0'], 'negative' => [-5], 'text' => ['abc']];
    }

    #[DataProvider('noRetention')]
    public function test_nothing_is_deleted_without_a_positive_retention(mixed $value): void
    {
        config()->set('spa-analytics.retention_days', $value);
        $this->rolledDay('2026-01-01');
        $this->pageView('2026-01-01 10:00:00');

        $this->artisan('spa-analytics:prune')->expectsOutputToContain('off')->assertExitCode(0);

        $this->assertSame(1, AnalyticsEvent::query()->count());
    }

    public function test_rolled_up_days_before_the_cutoff_lose_their_raw_rows_but_keep_their_rollups(): void
    {
        $this->rolledDay('2026-03-05');
        $this->rolledDay('2026-03-06');
        $this->pageView('2026-03-05 10:00:00');
        $this->pageView('2026-03-06 23:59:59');
        $this->visit('2026-03-05 10:00:00', '2026-03-05 10:30:00');
        $kept = $this->pageView('2026-03-07 00:00:00');
        $keptSession = $this->visit('2026-03-08 10:00:00', '2026-03-08 10:30:00');

        $this->artisan('spa-analytics:prune')->assertExitCode(0);

        $this->assertSame([$kept->id], AnalyticsEvent::query()->pluck('id')->all());
        $this->assertSame([$keptSession->id], AnalyticsSession::query()->pluck('id')->all());
        $this->assertSame(2, AnalyticsRollup::query()->count());
    }

    public function test_the_cutoff_is_the_start_of_the_day_retention_days_ago(): void
    {
        $this->rolledDay('2026-03-06');
        $this->rolledDay('2026-03-07');
        $this->pageView('2026-03-06 23:59:59');
        $keep = $this->pageView('2026-03-07 00:00:00');

        $this->artisan('spa-analytics:prune')->assertExitCode(0);

        $this->assertSame([$keep->id], AnalyticsEvent::query()->pluck('id')->all());
    }

    public function test_an_unrolled_day_is_never_pruned_and_the_command_says_how_to_fix_it(): void
    {
        $this->pageView('2026-03-05 10:00:00');
        $this->visit('2026-03-05 10:00:00', '2026-03-05 10:30:00');

        $this->artisan('spa-analytics:prune')
            ->expectsOutputToContain('spa-analytics:rollup --since=2026-03-05')
            ->assertExitCode(0);

        $this->assertSame(1, AnalyticsEvent::query()->count());
        $this->assertSame(1, AnalyticsSession::query()->count());
    }

    public function test_the_advised_rollup_command_unblocks_the_prune(): void
    {
        $this->pageView('2026-03-05 10:00:00');

        $this->artisan('spa-analytics:prune')->assertExitCode(0);
        $this->assertSame(1, AnalyticsEvent::query()->count());

        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-05', '--period' => 'day'])->assertExitCode(0);
        $this->artisan('spa-analytics:prune')->assertExitCode(0);

        $this->assertSame(0, AnalyticsEvent::query()->count());
        $this->assertSame(1, AnalyticsRollup::query()->where('period', RollupPeriod::Day)->where('bucket_start', '2026-03-05 00:00:00')->where('dimension', RollupDimension::Total)->value('page_views'));
    }

    public function test_pruning_stops_at_the_first_gap_in_the_rollups(): void
    {
        $this->rolledDay('2026-03-04');
        $this->rolledDay('2026-03-06');
        $this->pageView('2026-03-04 10:00:00');
        $this->pageView('2026-03-05 10:00:00');
        $this->pageView('2026-03-06 10:00:00');

        $this->artisan('spa-analytics:prune')->expectsOutputToContain('2026-03-05')->assertExitCode(0);

        $this->assertSame(2, AnalyticsEvent::query()->count());
        $this->assertSame(0, AnalyticsEvent::query()->whereDate('occurred_at', '2026-03-04')->count());
    }

    public function test_a_session_that_ends_after_the_cutoff_is_kept(): void
    {
        $this->rolledDay('2026-03-06');
        $this->rolledDay('2026-03-05');
        $straddling = $this->visit('2026-03-06 23:50:00', '2026-03-07 00:10:00');
        $this->visit('2026-03-05 09:00:00', '2026-03-05 09:10:00');

        $this->artisan('spa-analytics:prune')->assertExitCode(0);

        $this->assertSame([$straddling->id], AnalyticsSession::query()->pluck('id')->all());
    }

    public function test_deleting_works_in_chunks(): void
    {
        $this->rolledDay('2026-03-05');
        foreach (range(1, 25) as $i) {
            $this->pageView('2026-03-05 10:00:00');
            $this->visit('2026-03-05 10:00:00', '2026-03-05 10:05:00');
        }

        $result = (new RetentionPruner(chunkSize: 10))->prune(Carbon::now()->toImmutable());

        $this->assertSame(25, $result['events']);
        $this->assertSame(25, $result['sessions']);
        $this->assertSame(0, AnalyticsEvent::query()->count());
        $this->assertSame(0, AnalyticsSession::query()->count());
    }

    public function test_a_backfill_over_pruned_days_keeps_their_rollups(): void
    {
        AnalyticsRollup::factory()->create([
            'period' => RollupPeriod::Day,
            'bucket_start' => Carbon::parse('2026-03-05'),
            'dimension' => RollupDimension::Total,
            'value' => '',
            'page_views' => 42,
        ]);
        $this->pageView('2026-03-05 10:00:00');
        $this->artisan('spa-analytics:prune')->assertExitCode(0);

        $this->artisan('spa-analytics:rollup', ['--since' => '2026-03-05'])->assertExitCode(0);

        $this->assertSame(42, AnalyticsRollup::query()->where('period', RollupPeriod::Day)->where('bucket_start', '2026-03-05 00:00:00')->value('page_views'));
    }

    public function test_an_empty_database_is_fine(): void
    {
        $this->artisan('spa-analytics:prune')->assertExitCode(0);
    }
}
