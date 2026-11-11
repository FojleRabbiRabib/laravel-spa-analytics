<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories;

use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsState>
 */
class AnalyticsStateFactory extends Factory
{
    protected $model = AnalyticsState::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => AnalyticsState::PRUNED_THROUGH,
            'value' => now()->startOfDay()->toDateTimeString(),
        ];
    }

    /**
     * The moment up to which raw rows were pruned.
     */
    public function prunedThrough(string $moment): static
    {
        return $this->state(fn (): array => [
            'name' => AnalyticsState::PRUNED_THROUGH,
            'value' => $moment,
        ]);
    }
}
