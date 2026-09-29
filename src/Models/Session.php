<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Models;

use Carbon\CarbonInterface;
use FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories\SessionFactory;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(SessionFactory::class)]
class Session extends Model
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
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'session_id');
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
