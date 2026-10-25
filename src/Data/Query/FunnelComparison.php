<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

final readonly class FunnelComparison
{
    /**
     * @param  array<int, Change>  $stepChanges  The change of the users of each step, in the order of the steps.
     * @param  Change  $conversionRate  The change of the overall conversion, a difference of ratios.
     */
    public function __construct(
        public Funnel $current,
        public Funnel $previous,
        public array $stepChanges,
        public Change $conversionRate,
    ) {}

    public static function between(Funnel $current, Funnel $previous): self
    {
        $changes = [];

        foreach ($current->steps as $index => $step) {
            $changes[$index] = Change::between($step->users, $previous->steps[$index]->users ?? 0);
        }

        return new self($current, $previous, $changes, Change::between($current->conversionRate, $previous->conversionRate));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'current' => $this->current->toArray(),
            'previous' => $this->previous->toArray(),
            'stepChanges' => array_map(fn (Change $change): array => $change->toArray(), $this->stepChanges),
            'conversionRate' => $this->conversionRate->toArray(),
        ];
    }
}
