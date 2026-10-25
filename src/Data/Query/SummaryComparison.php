<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

final readonly class SummaryComparison
{
    /**
     * @param  array<string, Change>  $changes  The change of each number of the summary, by the name it has there.
     */
    public function __construct(
        public Summary $current,
        public Summary $previous,
        public array $changes,
    ) {}

    public static function between(Summary $current, Summary $previous): self
    {
        $numbers = fn (Summary $summary): array => [
            'pageViews' => $summary->pageViews,
            'users' => $summary->users,
            'newUsers' => $summary->newUsers,
            'returningUsers' => $summary->returningUsers,
            'sessions' => $summary->sessions,
            'bounces' => $summary->bounces,
            'bounceRate' => $summary->bounceRate,
            'avgSessionDuration' => $summary->avgSessionDuration,
            'events' => $summary->events,
            'goalCompletions' => $summary->goalCompletions,
            'conversionRate' => $summary->conversionRate,
            'engagedSeconds' => $summary->engagedSeconds,
            'avgEngagementPerUser' => $summary->avgEngagementPerUser,
            'avgEngagementPerSession' => $summary->avgEngagementPerSession,
        ];

        return new self($current, $previous, [
            ...Change::all($numbers($current), $numbers($previous)),
            'revenue' => Change::money($current->revenue, $previous->revenue),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'current' => $this->current->toArray(),
            'previous' => $this->previous->toArray(),
            'changes' => array_map(fn (Change $change): array => $change->toArray(), $this->changes),
        ];
    }
}
