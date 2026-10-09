<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

final readonly class TopRow
{
    /**
     * Sessions, bounces and the durations of a path row belong to the sessions that entered on that path.
     * The engaged seconds and their average are filled for path rows only; every other dimension has 0 and 0.0.
     *
     * @param  bool  $usersExact  False when part of the range lies in days whose raw rows were pruned, so users there are summed daily uniques.
     * @param  string  $revenue  A decimal string with two places.
     */
    public function __construct(
        public string $value,
        public int $pageViews,
        public int $users,
        public bool $usersExact,
        public int $sessions,
        public int $bounces,
        public float $bounceRate,
        public float $avgSessionDuration,
        public int $events,
        public string $revenue,
        public int $engagedSeconds,
        public float $avgEngagement,
    ) {}

    /**
     * @return array<string, int|float|string|bool>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'pageViews' => $this->pageViews,
            'users' => $this->users,
            'usersExact' => $this->usersExact,
            'sessions' => $this->sessions,
            'bounces' => $this->bounces,
            'bounceRate' => $this->bounceRate,
            'avgSessionDuration' => $this->avgSessionDuration,
            'events' => $this->events,
            'revenue' => $this->revenue,
            'engagedSeconds' => $this->engagedSeconds,
            'avgEngagement' => $this->avgEngagement,
        ];
    }
}
