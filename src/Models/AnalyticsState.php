<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Models;

use FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories\AnalyticsStateFactory;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Concerns\UsesAnalyticsConnection;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per piece of durable package state, for example the moment up to which raw rows were pruned.
 *
 * @property string $name
 * @property string $value
 */
#[UseFactory(AnalyticsStateFactory::class)]
class AnalyticsState extends Model
{
    use HasFactory;
    use UsesAnalyticsConnection;

    public const PRUNED_THROUGH = 'pruned_through';

    protected $table = 'analytics_state';

    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
