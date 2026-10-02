<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

final readonly class GoalRow
{
    /**
     * @param  int  $users  Distinct visitors who completed the goal.
     * @param  bool  $usersExact  False when part of the range lies in days whose raw rows were pruned, so users there are summed daily uniques.
     * @param  string  $revenue  A decimal string with two places.
     * @param  float  $conversionRate  Users who completed the goal and also viewed a page, divided by all users of the range; never above the overall conversion rate.
     */
    public function __construct(
        public string $name,
        public int $completions,
        public int $users,
        public bool $usersExact,
        public string $revenue,
        public float $conversionRate,
    ) {}

    /**
     * @return array<string, int|float|string|bool>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'completions' => $this->completions,
            'users' => $this->users,
            'usersExact' => $this->usersExact,
            'revenue' => $this->revenue,
            'conversionRate' => $this->conversionRate,
        ];
    }
}
