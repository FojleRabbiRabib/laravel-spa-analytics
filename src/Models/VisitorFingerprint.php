<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Models;

use FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories\VisitorFingerprintFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
