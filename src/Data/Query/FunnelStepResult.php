<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\FunnelStepType;

final readonly class FunnelStepResult
{
    /**
     * @param  int  $users  Distinct visitors who reached this step after completing the steps before it.
     * @param  float  $fromPrevious  Users divided by the users of the previous step; 1.0 for the first step.
     * @param  float  $fromFirst  Users divided by the users of the first step.
     */
    public function __construct(
        public FunnelStepType $type,
        public string $value,
        public string $label,
        public int $users,
        public float $fromPrevious,
        public float $fromFirst,
    ) {}

    /**
     * @return array<string, int|float|string>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'value' => $this->value,
            'label' => $this->label,
            'users' => $this->users,
            'fromPrevious' => $this->fromPrevious,
            'fromFirst' => $this->fromFirst,
        ];
    }
}
