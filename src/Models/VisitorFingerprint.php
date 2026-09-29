<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Models;

use Carbon\CarbonInterface;
use FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories\VisitorFingerprintFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $visitor_id
 * @property string $stable_hash
 * @property ?string $canvas_hash
 * @property ?string $audio_hash
 * @property ?string $webgl_hash
 * @property ?string $tls_hash
 * @property CarbonInterface $first_seen_at
 * @property CarbonInterface $last_seen_at
 */
#[UseFactory(VisitorFingerprintFactory::class)]
class VisitorFingerprint extends Model
{
    use HasFactory;

    protected $table = 'analytics_visitor_fingerprints';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
