<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Models;

use Carbon\CarbonInterface;
use FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories\AnalyticsEventFactory;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(AnalyticsEventFactory::class)]
class AnalyticsEvent extends Model
{
    use HasFactory;

    protected $table = 'analytics_events';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'referrer_type' => ReferrerType::class,
            'status' => 'integer',
            'is_bot' => 'boolean',
            'occurred_at' => 'datetime',
            'properties' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AnalyticsSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSession::class, 'session_id');
    }

    /**
     * Exclude events flagged as bot traffic.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function notBots(Builder $query): void
    {
        $query->where('is_bot', false);
    }

    /**
     * Limit events to an inclusive occurred_at range.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function between(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereBetween('occurred_at', [$from, $to]);
    }
}
