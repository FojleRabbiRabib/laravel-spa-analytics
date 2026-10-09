<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Models;

use Carbon\CarbonInterface;
use FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories\AnalyticsRollupFactory;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property RollupPeriod $period
 * @property CarbonInterface $bucket_start
 * @property RollupDimension $dimension
 * @property string $value
 * @property string $value_hash
 * @property int $page_views
 * @property int $visitors
 * @property int $sessions
 * @property int $bounces
 * @property int $duration_seconds
 * @property int $events
 * @property string $revenue
 * @property int $engaged_seconds
 */
#[UseFactory(AnalyticsRollupFactory::class)]
class AnalyticsRollup extends Model
{
    use HasFactory;

    protected $table = 'analytics_rollups';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Keep the exact-match hash of the value in step with it, so the unique key is case and space exact on every engine.
     */
    protected static function booted(): void
    {
        static::saving(function (self $rollup): void {
            $rollup->value_hash = sha1((string) $rollup->value);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => RollupPeriod::class,
            'dimension' => RollupDimension::class,
            'bucket_start' => 'datetime',
            'page_views' => 'integer',
            'visitors' => 'integer',
            'sessions' => 'integer',
            'bounces' => 'integer',
            'duration_seconds' => 'integer',
            'events' => 'integer',
            'revenue' => 'decimal:2',
            'engaged_seconds' => 'integer',
        ];
    }
}
