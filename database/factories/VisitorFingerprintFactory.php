<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories;

use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorFingerprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitorFingerprint>
 */
class VisitorFingerprintFactory extends Factory
{
    protected $model = VisitorFingerprint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visitor_id' => fake()->uuid(),
            'stable_hash' => hash('sha256', fake()->uuid()),
            'canvas_hash' => hash('sha256', fake()->uuid()),
            'audio_hash' => hash('sha256', fake()->uuid()),
            'webgl_hash' => hash('sha256', fake()->uuid()),
            'tls_hash' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
