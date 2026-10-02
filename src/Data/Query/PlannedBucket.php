<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;

final readonly class PlannedBucket
{
    public function __construct(
        public RollupPeriod $period,
        public CarbonImmutable $start,
    ) {}

    /**
     * The exclusive end of the bucket.
     */
    public function end(): CarbonImmutable
    {
        return $this->period->end($this->start);
    }
}
