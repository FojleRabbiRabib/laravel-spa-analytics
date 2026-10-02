<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

use Carbon\CarbonImmutable;

final readonly class Funnel
{
    /**
     * @param  array<int, FunnelStepResult>  $steps
     * @param  float  $conversionRate  Users of the last step divided by users of the first.
     * @param  ?CarbonImmutable  $coveredFrom  Where the counted raw events start; null when there are none to count.
     * @param  bool  $complete  False when raw rows of the range were pruned, so only the part from $coveredFrom is counted.
     * @param  ?CarbonImmutable  $through  The end of the latest completed rolled-up hour; nothing after it is counted.
     */
    public function __construct(
        public array $steps,
        public float $conversionRate,
        public ?CarbonImmutable $coveredFrom,
        public bool $complete,
        public ?CarbonImmutable $through,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'steps' => array_map(fn (FunnelStepResult $step): array => $step->toArray(), $this->steps),
            'conversionRate' => $this->conversionRate,
            'coveredFrom' => $this->coveredFrom?->toIso8601String(),
            'complete' => $this->complete,
            'through' => $this->through?->toIso8601String(),
        ];
    }
}
