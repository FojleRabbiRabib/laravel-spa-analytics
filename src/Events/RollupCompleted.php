<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched once at the end of a rollup run, with the number of hourly and daily buckets it built, the days it
 * skipped because their raw rows may already be pruned, and the buckets that failed to build.
 */
final readonly class RollupCompleted
{
    use Dispatchable;

    public function __construct(
        public int $hours,
        public int $days,
        public int $skippedDays,
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public int $failed = 0,
    ) {}
}
