<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

use Carbon\CarbonImmutable;

final readonly class RangePlan
{
    /**
     * @param  CarbonImmutable  $from  The snapped start, inclusive.
     * @param  CarbonImmutable  $to  The snapped end, exclusive, before it is limited to what has been rolled up.
     * @param  CarbonImmutable  $through  The end of the latest rolled-up bucket.
     * @param  CarbonImmutable  $effectiveTo  The end actually read: the snapped end limited to $through.
     * @param  array<int, PlannedBucket>  $buckets  Whole-day and hour buckets that cover the range exactly once.
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $through,
        public CarbonImmutable $effectiveTo,
        public array $buckets,
    ) {}
}
