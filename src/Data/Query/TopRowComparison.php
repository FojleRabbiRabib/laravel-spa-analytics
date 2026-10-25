<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

final readonly class TopRowComparison
{
    /**
     * @param  ?TopRow  $previous  Null when the value had no row in the previous period; its changes then count the previous numbers as 0.
     * @param  array<string, Change>  $changes
     */
    public function __construct(
        public TopRow $current,
        public ?TopRow $previous,
        public array $changes,
    ) {}

    public static function between(TopRow $current, ?TopRow $previous): self
    {
        $numbers = fn (?TopRow $row): array => [
            'pageViews' => $row->pageViews ?? 0,
            'users' => $row->users ?? 0,
            'sessions' => $row->sessions ?? 0,
            'bounces' => $row->bounces ?? 0,
            'bounceRate' => $row->bounceRate ?? 0.0,
            'avgSessionDuration' => $row->avgSessionDuration ?? 0.0,
            'events' => $row->events ?? 0,
            'engagedSeconds' => $row->engagedSeconds ?? 0,
            'avgEngagement' => $row->avgEngagement ?? 0.0,
        ];

        return new self($current, $previous, [
            ...Change::all($numbers($current), $numbers($previous)),
            'revenue' => Change::money($current->revenue, $previous->revenue ?? '0.00'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'current' => $this->current->toArray(),
            'previous' => $this->previous?->toArray(),
            'changes' => array_map(fn (Change $change): array => $change->toArray(), $this->changes),
        ];
    }
}
