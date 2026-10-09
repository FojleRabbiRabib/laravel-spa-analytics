<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

use Carbon\CarbonImmutable;

final readonly class Summary
{
    /**
     * @param  bool  $usersExact  False when part of the range lies in days whose raw rows were pruned, so users there are summed daily uniques.
     * @param  string  $revenue  A decimal string with two places.
     * @param  ?CarbonImmutable  $through  The end of the latest rolled-up hour; nothing after it is counted. Null when nothing has been rolled up.
     * @param  bool  $incomplete  True when an hour inside the range has no rollup row.
     */
    public function __construct(
        public int $pageViews,
        public int $users,
        public bool $usersExact,
        public int $newUsers,
        public int $returningUsers,
        public int $sessions,
        public int $bounces,
        public float $bounceRate,
        public float $avgSessionDuration,
        public int $events,
        public int $goalCompletions,
        public string $revenue,
        public float $conversionRate,
        public ?CarbonImmutable $through,
        public bool $incomplete,
        public int $engagedSeconds,
        public float $avgEngagementPerUser,
        public float $avgEngagementPerSession,
    ) {}

    /**
     * @return array<string, int|float|string|bool|null>
     */
    public function toArray(): array
    {
        return [
            'pageViews' => $this->pageViews,
            'users' => $this->users,
            'usersExact' => $this->usersExact,
            'newUsers' => $this->newUsers,
            'returningUsers' => $this->returningUsers,
            'sessions' => $this->sessions,
            'bounces' => $this->bounces,
            'bounceRate' => $this->bounceRate,
            'avgSessionDuration' => $this->avgSessionDuration,
            'events' => $this->events,
            'goalCompletions' => $this->goalCompletions,
            'revenue' => $this->revenue,
            'conversionRate' => $this->conversionRate,
            'through' => $this->through?->toIso8601String(),
            'incomplete' => $this->incomplete,
            'engagedSeconds' => $this->engagedSeconds,
            'avgEngagementPerUser' => $this->avgEngagementPerUser,
            'avgEngagementPerSession' => $this->avgEngagementPerSession,
        ];
    }
}
