<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use Illuminate\Database\Eloquent\Builder;

class RetentionPruner
{
    public function __construct(
        private readonly int $chunkSize = 1000,
        private readonly RetentionPolicy $retention = new RetentionPolicy,
    ) {}

    /**
     * Delete raw events and sessions older than the retention cutoff, but only for days that were rolled up.
     *
     * Pruning stops at the first day without a daily total rollup, so nothing is deleted before it was counted.
     * Sessions go by their last activity, so a session still running at the cutoff is kept.
     *
     * @return array{events: int, sessions: int, retention: bool, blockedDay: ?CarbonImmutable}
     */
    public function prune(CarbonImmutable $now): array
    {
        $cutoff = $this->retention->cutoff($now);
        $result = ['events' => 0, 'sessions' => 0, 'retention' => $cutoff !== null, 'blockedDay' => null];

        if ($cutoff === null) {
            return $result;
        }

        $earliest = $this->earliestDay();

        if ($earliest === null || $earliest->greaterThanOrEqualTo($cutoff)) {
            return $result;
        }

        $rolled = AnalyticsRollup::query()
            ->where('period', RollupPeriod::Day)
            ->where('dimension', RollupDimension::Total)
            ->where('bucket_start', '>=', $earliest->toDateTimeString())
            ->where('bucket_start', '<', $cutoff->toDateTimeString())
            ->pluck('bucket_start')
            ->map(fn ($bucket): string => $bucket->toDateTimeString())
            ->flip();

        $through = $earliest;

        while ($through->lessThan($cutoff)) {
            if (! $rolled->has($through->toDateTimeString())) {
                $result['blockedDay'] = $through;

                break;
            }

            $through = $through->addDay();
        }

        if ($through->equalTo($earliest)) {
            return $result;
        }

        $result['events'] = $this->deleteInChunks(AnalyticsEvent::query()->where('occurred_at', '<', $through->toDateTimeString()));
        $result['sessions'] = $this->deleteInChunks(AnalyticsSession::query()->where('started_at', '<', $through->toDateTimeString())->where('last_seen_at', '<', $through->toDateTimeString()));

        return $result;
    }

    private function earliestDay(): ?CarbonImmutable
    {
        $first = collect([
            AnalyticsEvent::query()->min('occurred_at'),
            AnalyticsSession::query()->min('started_at'),
        ])->filter()->map(fn ($moment): CarbonImmutable => CarbonImmutable::parse($moment))->sort()->first();

        return $first?->startOfDay();
    }

    /**
     * @param  Builder<AnalyticsEvent>|Builder<AnalyticsSession>  $query
     */
    private function deleteInChunks(Builder $query): int
    {
        $deleted = 0;

        while (($ids = (clone $query)->orderBy('id')->limit($this->chunkSize)->pluck('id'))->isNotEmpty()) {
            $deleted += $query->getModel()->newQuery()->whereKey($ids->all())->delete();
        }

        return $deleted;
    }
}
