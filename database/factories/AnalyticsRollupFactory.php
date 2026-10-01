<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsRollup>
 */
class AnalyticsRollupFactory extends Factory
{
    protected $model = AnalyticsRollup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period' => RollupPeriod::Hour,
            'bucket_start' => now()->startOfHour(),
            'dimension' => RollupDimension::Total,
            'value' => '',
            'page_views' => 0,
            'visitors' => 0,
            'sessions' => 0,
            'bounces' => 0,
            'duration_seconds' => 0,
            'events' => 0,
            'revenue' => 0,
        ];
    }
}
