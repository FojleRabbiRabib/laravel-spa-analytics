<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Models;

use Carbon\CarbonInterface;
use FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories\AnalyticsSessionFactory;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $visitor_id
 * @property CarbonInterface $started_at
 * @property CarbonInterface $last_seen_at
 * @property string $entry_path
 * @property string $exit_path
 * @property int $page_views
 * @property ?string $referrer_host
 * @property ReferrerType $referrer_type
 * @property ?string $utm_source
 * @property ?string $utm_medium
 * @property ?string $utm_campaign
 * @property ?string $utm_term
 * @property ?string $utm_content
 * @property bool $is_new_visitor
 * @property bool $is_bot
 */
#[UseFactory(AnalyticsSessionFactory::class)]
class AnalyticsSession extends Model
{
    use HasFactory;

    protected $table = 'analytics_sessions';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'page_views' => 'integer',
            'referrer_type' => ReferrerType::class,
            'is_new_visitor' => 'boolean',
            'is_bot' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AnalyticsEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class, 'session_id');
    }

    /**
     * Exclude sessions flagged as bot traffic.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function notBots(Builder $query): void
    {
        $query->where('is_bot', false);
    }

    /**
     * Limit sessions to an inclusive started_at range.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function between(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereBetween('started_at', [$from, $to]);
    }
}
