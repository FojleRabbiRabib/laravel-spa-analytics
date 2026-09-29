<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories;

use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitorLink>
 */
class VisitorLinkFactory extends Factory
{
    protected $model = VisitorLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visitor_id' => fake()->uuid(),
            'linked_to' => fake()->uuid(),
            'created_at' => now(),
        ];
    }
}
