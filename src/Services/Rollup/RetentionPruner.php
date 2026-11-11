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
        private readonly RollupVerifier $verifier = new RollupVerifier,
        private readonly PruneMarker $marker = new PruneMarker,
    ) {}

    /**
     * Delete raw events and sessions older than the retention cutoff, but only for days that were rolled up.
     *
     * Pruning stops at the first day without a daily total rollup, or whose rollup counted fewer page views or
     * events than its raw rows hold (late writes), so nothing is deleted before it was counted. A day whose raw rows
     * are already gone (the rollup counted more than is left) is passed, and a late row on it is deleted. Sessions go
     * by their last activity, so a session still running at the cutoff is kept.
     *
     * The moment pruned through is saved before the deletes, so a rollup of a pruned day is never rebuilt from the
     * few raw rows that may be left. It starts the walk at that moment and deletes anything older that still turns up.
     *
     * @return array{events: int, sessions: int, retention: bool, blockedDay: ?CarbonImmutable, blockedReason: ?string}
     */
    public function prune(CarbonImmutable $now): array
    {
        $cutoff = $this->retention->cutoff($now);
        $result = ['events' => 0, 'sessions' => 0, 'retention' => $cutoff !== null, 'blockedDay' => null, 'blockedReason' => null];

        if ($cutoff === null) {
            return $result;
        }

        $earliest = $this->earliestDay();

        if ($earliest === null || $earliest->greaterThanOrEqualTo($cutoff)) {
            return $result;
        }

        $marked = $this->marker->get();
        $start = $marked !== null && $marked->greaterThan($earliest) ? $marked : $earliest;

        $rolled = AnalyticsRollup::query()
            ->where('period', RollupPeriod::Day)
            ->where('dimension', RollupDimension::Total)
            ->where('bucket_start', '>=', $start->toDateTimeString())
            ->where('bucket_start', '<', $cutoff->toDateTimeString())
            ->pluck('bucket_start')
            ->map(fn ($bucket): string => $bucket->toDateTimeString())
            ->flip();

        $through = $start;

        while ($through->lessThan($cutoff)) {
            if (! $rolled->has($through->toDateTimeString())) {
                $result['blockedDay'] = $through;
                $result['blockedReason'] = 'missing';

                break;
            }

            if (! $this->verifier->isShrinking($through) && $this->verifier->isBehind($through)) {
                $result['blockedDay'] = $through;
                $result['blockedReason'] = 'behind';

                break;
            }

            $through = $through->addDay();
        }

        if ($through->equalTo($start) && $marked === null) {
            return $result;
        }

        if ($through->greaterThan($start)) {
            $this->marker->advance($through);
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
