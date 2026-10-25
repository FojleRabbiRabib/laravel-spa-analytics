<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

final readonly class GoalRowComparison
{
    /**
     * @param  ?GoalRow  $previous  Null when the goal had no completions in the previous period; its changes then count the previous numbers as 0.
     * @param  array<string, Change>  $changes
     */
    public function __construct(
        public GoalRow $current,
        public ?GoalRow $previous,
        public array $changes,
    ) {}

    public static function between(GoalRow $current, ?GoalRow $previous): self
    {
        $numbers = fn (?GoalRow $row): array => [
            'completions' => $row->completions ?? 0,
            'users' => $row->users ?? 0,
            'conversionRate' => $row->conversionRate ?? 0.0,
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
