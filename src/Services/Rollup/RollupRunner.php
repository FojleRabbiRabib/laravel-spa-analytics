<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;

class RollupRunner
{
    public function __construct(
        private readonly RollupBuilder $builder,
        private readonly RetentionPolicy $retention,
        private readonly DimensionSettings $settings,
    ) {}

    /**
     * Delete the stored rows of the dimensions turned off with rollups.disabled_dimensions, in both periods and in
     * chunks. Nothing else is touched, and the dimensions Stats needs are never in the list.
     *
     * @return int The number of rows deleted.
     */
    public function purgeDisabled(int $chunkSize = 1000): int
    {
        $disabled = $this->settings->disabled();
        $deleted = 0;

        if ($disabled === []) {
            return $deleted;
        }

        $query = AnalyticsRollup::query()->whereIn('dimension', $disabled);

        while (($ids = (clone $query)->orderBy('id')->limit($chunkSize)->pluck('id'))->isNotEmpty()) {
            $deleted += AnalyticsRollup::query()->whereKey($ids->all())->delete();
        }

        return $deleted;
    }

    /**
     * Recompute the hourly and daily buckets from the start moment up to the last one that has ended.
     *
     * The running hour and day are left alone: a row built early would look complete once the scheduler misses the
     * runs that follow, and prune would then delete the raw rows it undercounted. A missing bucket is reported as
     * incomplete instead. Without a start, the configured lookback hours are recomputed. Before the retention cutoff a bucket is only
     * computed when its day has no daily rollup yet: such a day was never pruned, so its raw rows are still all
     * there, while a day that has a rollup may already be pruned and recomputing it would replace good numbers
     * with zeros.
     *
     * @param  array<int, RollupPeriod>  $periods
     * @return array{hours: int, days: int, skippedDays: int, cutoff: ?CarbonImmutable}
     */
    public function run(CarbonImmutable $now, ?CarbonImmutable $since = null, array $periods = [RollupPeriod::Hour, RollupPeriod::Day]): array
    {
        $from = $since ?? $now->subHours(max(1, (int) config('spa-analytics.rollups.lookback_hours')));
        $cutoff = $this->retention->cutoff($now);
        $rolledDays = $cutoff !== null && $from->lessThan($cutoff) ? $this->rolledDays($from, $cutoff) : [];
        $skipped = [];
        $counts = ['hours' => 0, 'days' => 0, 'skippedDays' => 0, 'cutoff' => $cutoff];

        foreach ($periods as $period) {
            for ($start = $period->start($from); $period->end($start)->lessThanOrEqualTo($now); $start = $period->end($start)) {
                $day = RollupPeriod::Day->start($start)->toDateTimeString();

                if ($cutoff !== null && $start->lessThan($cutoff) && isset($rolledDays[$day])) {
                    $skipped[$day] = true;

                    continue;
                }

                $this->builder->rollup($period, $start);
                $counts[$period->is(RollupPeriod::Hour) ? 'hours' : 'days']++;
            }
        }

        $counts['skippedDays'] = count($skipped);

        return $counts;
    }

    /**
     * The days from the start up to the cutoff that already have a daily total row.
     *
     * @return array<string, true>
     */
    private function rolledDays(CarbonImmutable $from, CarbonImmutable $cutoff): array
    {
        $days = [];

        $buckets = AnalyticsRollup::query()
            ->where('period', RollupPeriod::Day)
            ->where('dimension', RollupDimension::Total)
            ->where('bucket_start', '>=', RollupPeriod::Day->start($from)->toDateTimeString())
            ->where('bucket_start', '<', $cutoff->toDateTimeString())
            ->pluck('bucket_start');

        foreach ($buckets as $bucket) {
            $days[$bucket->toDateTimeString()] = true;
        }

        return $days;
    }
}
