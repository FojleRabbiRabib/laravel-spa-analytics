<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup;

use Carbon\CarbonImmutable;

class RetentionPolicy
{
    /**
     * The start of the first day that raw data is still kept, or null when retention is off.
     *
     * A null, empty, zero, negative or non-numeric retention_days keeps everything.
     */
    public function cutoff(CarbonImmutable $now): ?CarbonImmutable
    {
        $days = config('spa-analytics.retention_days');

        if (! is_numeric($days) || (int) $days < 1) {
            return null;
        }

        return $now->subDays((int) $days)->startOfDay();
    }
}
