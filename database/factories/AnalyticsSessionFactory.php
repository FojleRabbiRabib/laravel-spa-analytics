<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsSession>
 */
class AnalyticsSessionFactory extends Factory
{
    protected $model = AnalyticsSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = '/'.fake()->slug(2);

        return [
            'visitor_id' => fake()->uuid(),
            'started_at' => now(),
            'last_seen_at' => now(),
            'entry_path' => $path,
            'exit_path' => $path,
            'page_views' => 1,
            'referrer_host' => null,
            'referrer_type' => ReferrerType::Direct,
            'is_new_visitor' => true,
            'is_bot' => false,
        ];
    }

    /**
     * Mark the session as bot traffic.
     */
    public function bot(): static
    {
        return $this->state(fn (): array => ['is_bot' => true]);
    }
}
