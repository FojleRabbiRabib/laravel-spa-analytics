<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\RollupCompleted;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use Illuminate\Support\Facades\Log;

class RollupRunner
{
    private const HEAL_DAYS = 7;

    public function __construct(
        private readonly RollupBuilder $builder,
        private readonly DimensionSettings $settings,
        private readonly RollupVerifier $verifier,
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
     * runs that follow, and prune would then delete the raw rows it undercounted. Without a start, the configured
     * lookback hours are recomputed, and so are the days of the last seven that have no daily rollup yet or whose raw
     * page views or events exceed it (a scheduler gap, or queued writes that landed late). A day whose daily rollup
     * counted more page views or events than its raw rows hold is left alone: its raw rows may be pruned, so
     * recomputing it would replace good numbers with smaller ones. A bucket that fails is logged and counted, and the
     * others are still built.
     *
     * @param  array<int, RollupPeriod>  $periods
     * @return array{hours: int, days: int, skippedDays: int, healedDays: int, failed: int, rawFrom: ?CarbonImmutable}
     */
    public function run(CarbonImmutable $now, ?CarbonImmutable $since = null, array $periods = [RollupPeriod::Hour, RollupPeriod::Day]): array
    {
        $from = $since ?? $now->subHours(max(1, (int) config('spa-analytics.rollups.lookback_hours')));
        $rawFrom = $this->rawFrom();
        $skipped = [];
        $counts = ['hours' => 0, 'days' => 0, 'skippedDays' => 0, 'healedDays' => 0, 'failed' => 0, 'rawFrom' => $rawFrom];

        foreach ($periods as $period) {
            for ($start = $period->start($from); $period->end($start)->lessThanOrEqualTo($now); $start = $period->end($start)) {
                $day = RollupPeriod::Day->start($start);

                if ($skipped[$day->toDateTimeString()] ??= $this->verifier->isShrinking($day)) {
                    continue;
                }

                $this->build($period, $start, $counts);
            }
        }

        $counts['skippedDays'] = count(array_filter($skipped));

        if ($since === null && $rawFrom !== null) {
            $this->heal($now, RollupPeriod::Day->start($from), $rawFrom, $periods, $counts);
        }

        try {
            RollupCompleted::dispatch($counts['hours'], $counts['days'], $counts['skippedDays'], $from, $now, $counts['failed']);
        } catch (\Throwable $e) {
            Log::warning('spa-analytics: rollup listener failed', ['exception' => $e::class, 'code' => $e->getCode()]);
        }

        return $counts;
    }

    /**
     * Rebuild the ended days of the last seven that the lookback does not reach and that have no daily rollup or
     * fewer counted page views or events than their raw rows, together with their hours.
     *
     * @param  array<int, RollupPeriod>  $periods
     * @param  array{hours: int, days: int, skippedDays: int, healedDays: int, failed: int, rawFrom: ?CarbonImmutable}  $counts
     */
    private function heal(CarbonImmutable $now, CarbonImmutable $lookbackDay, CarbonImmutable $rawFrom, array $periods, array &$counts): void
    {
        $first = $now->subDays(self::HEAL_DAYS)->startOfDay();
        $first = $first->greaterThan($rawFrom) ? $first : $rawFrom;

        if (! $first->lessThan($lookbackDay)) {
            return;
        }

        $rolled = $this->rolledDays($first, $lookbackDay);

        for ($day = $first; $day->lessThan($lookbackDay) && RollupPeriod::Day->end($day)->lessThanOrEqualTo($now); $day = RollupPeriod::Day->end($day)) {
            if (isset($rolled[$day->toDateTimeString()]) && ! $this->verifier->isBehind($day)) {
                continue;
            }

            $counts['healedDays']++;

            if (in_array(RollupPeriod::Hour, $periods, true)) {
                for ($hour = $day; $hour->lessThan(RollupPeriod::Day->end($day)); $hour = RollupPeriod::Hour->end($hour)) {
                    $this->build(RollupPeriod::Hour, $hour, $counts);
                }
            }

            if (in_array(RollupPeriod::Day, $periods, true)) {
                $this->build(RollupPeriod::Day, $day, $counts);
            }
        }
    }

    /**
     * @param  array{hours: int, days: int, skippedDays: int, healedDays: int, failed: int, rawFrom: ?CarbonImmutable}  $counts
     */
    private function build(RollupPeriod $period, CarbonImmutable $start, array &$counts): void
    {
        try {
            $this->builder->rollup($period, $start);
            $counts[$period->is(RollupPeriod::Hour) ? 'hours' : 'days']++;
        } catch (\Throwable $e) {
            $counts['failed']++;
            Log::warning('spa-analytics: rollup bucket failed', ['exception' => $e::class, 'code' => $e->getCode()]);
        }
    }

    /**
     * The start of the day of the oldest raw event, or null when there are none.
     */
    private function rawFrom(): ?CarbonImmutable
    {
        $first = AnalyticsEvent::query()->min('occurred_at');

        return $first === null ? null : CarbonImmutable::parse($first)->startOfDay();
    }

    /**
     * The days from the start up to the limit that already have a daily total row.
     *
     * @return array<string, true>
     */
    private function rolledDays(CarbonImmutable $from, CarbonImmutable $limit): array
    {
        $days = [];

        $buckets = AnalyticsRollup::query()
            ->where('period', RollupPeriod::Day)
            ->where('dimension', RollupDimension::Total)
            ->where('bucket_start', '>=', RollupPeriod::Day->start($from)->toDateTimeString())
            ->where('bucket_start', '<', $limit->toDateTimeString())
            ->pluck('bucket_start');

        foreach ($buckets as $bucket) {
            $days[$bucket->toDateTimeString()] = true;
        }

        return $days;
    }
}
