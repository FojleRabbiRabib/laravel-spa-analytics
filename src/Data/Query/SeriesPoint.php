<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

use Carbon\CarbonImmutable;

final readonly class SeriesPoint
{
    /**
     * @param  int  $visitors  Distinct visitors of the bucket; for a day built from hour rows it is their sum.
     * @param  string  $revenue  A decimal string with two places.
     */
    public function __construct(
        public CarbonImmutable $start,
        public int $pageViews,
        public int $visitors,
        public int $sessions,
        public int $bounces,
        public int $events,
        public string $revenue,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'pageViews' => $this->pageViews,
            'visitors' => $this->visitors,
            'sessions' => $this->sessions,
            'bounces' => $this->bounces,
            'events' => $this->events,
            'revenue' => $this->revenue,
        ];
    }
}
