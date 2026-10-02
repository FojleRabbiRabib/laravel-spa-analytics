<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Query;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;

class RollupCoverage
{
    /**
     * The end of the latest rolled-up hour, or of the latest rolled-up day when there are no hour rows, or null when
     * nothing has been rolled up. A day row is not used while hour rows exist: the current day's row is written
     * early and only covers the hours so far. Only completed hours count as covered, because the rollup of the
     * running hour stops at the moment it was built while raw queries would keep seeing newer rows; the live window
     * belongs to realtime().
     */
    public function through(): ?CarbonImmutable
    {
        $timezone = (string) config('app.timezone');
        $completed = RollupPeriod::Hour->start(CarbonImmutable::now($timezone));

        foreach ([RollupPeriod::Hour, RollupPeriod::Day] as $period) {
            $latest = AnalyticsRollup::query()
                ->where('period', $period)
                ->where('dimension', RollupDimension::Total)
                ->max('bucket_start');

            if ($latest !== null) {
                return min($period->end(CarbonImmutable::parse((string) $latest, $timezone)), $completed);
            }
        }

        return null;
    }
}
