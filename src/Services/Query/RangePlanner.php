<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Query;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\PlannedBucket;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\RangePlan;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;

class RangePlanner
{
    /**
     * Cover a date range with rollup buckets: a day bucket where the whole day fits, hour buckets elsewhere.
     *
     * Rollups cannot count part of an hour, so the range is read in the app timezone and snapped outward to whole
     * hours; the snapped range is the one every query must use. The planned end is limited to $through, the end of
     * the latest rolled-up bucket, so counts and the raw queries never include an hour that is not rolled up yet.
     * A day and its hours are never both planned.
     *
     * @throws \InvalidArgumentException When the range is empty or reversed.
     */
    public function plan(CarbonInterface $from, CarbonInterface $to, CarbonInterface $through): RangePlan
    {
        $timezone = (string) config('app.timezone');
        $from = CarbonImmutable::instance($from)->setTimezone($timezone);
        $to = CarbonImmutable::instance($to)->setTimezone($timezone);
        $through = CarbonImmutable::instance($through)->setTimezone($timezone);

        if ($from->greaterThanOrEqualTo($to)) {
            throw new \InvalidArgumentException('The range must end after it starts.');
        }

        $start = RollupPeriod::Hour->start($from);
        $end = RollupPeriod::Hour->start($to);
        $end = $end->equalTo($to) ? $end : $end->addHour();
        $effectiveTo = $end->lessThan($through) ? $end : $through;

        $buckets = [];

        for ($cursor = $start; $cursor->lessThan($effectiveTo);) {
            $day = RollupPeriod::Day;

            if ($cursor->equalTo($day->start($cursor)) && $day->end($cursor)->lessThanOrEqualTo($effectiveTo)) {
                $buckets[] = new PlannedBucket($day, $cursor);
                $cursor = $day->end($cursor);

                continue;
            }

            $buckets[] = new PlannedBucket(RollupPeriod::Hour, $cursor);
            $cursor = RollupPeriod::Hour->end($cursor);
        }

        return new RangePlan($start, $end, $through, $effectiveTo, $buckets);
    }
}
