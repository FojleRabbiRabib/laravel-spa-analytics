<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsState;
use FojleRabbiRabib\LaravelSpaAnalytics\Support\AnalyticsDatabase;

class PruneMarker
{
    /**
     * The moment before which raw rows were pruned, or null when nothing was pruned yet. Every day before it keeps
     * its rollup as the only record, so it is never rebuilt from raw rows again.
     */
    public function get(): ?CarbonImmutable
    {
        $value = AnalyticsState::query()->whereKey(AnalyticsState::PRUNED_THROUGH)->value('value');

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    /**
     * Whether the day starts before the marker.
     */
    public function covers(CarbonImmutable $day): bool
    {
        $marked = $this->get();

        return $marked !== null && $day->lessThan($marked);
    }

    /**
     * Move the marker forward. It never moves back, so a slow prune cannot undo a later one.
     */
    public function advance(CarbonImmutable $through): void
    {
        AnalyticsDatabase::connection()->transaction(function () use ($through): void {
            $row = AnalyticsState::query()->lockForUpdate()->find(AnalyticsState::PRUNED_THROUGH);

            if ($row === null) {
                AnalyticsState::query()->create(['name' => AnalyticsState::PRUNED_THROUGH, 'value' => $through->toDateTimeString()]);

                return;
            }

            if (CarbonImmutable::parse($row->value)->lessThan($through)) {
                $row->update(['value' => $through->toDateTimeString()]);
            }
        });
    }
}
