<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Support\AnalyticsDatabase;

class RollupVerifier
{
    /**
     * Whether the raw rows of a day that has a daily rollup hold more page views or more custom events and goals than
     * the rollup counted, for example because queued writes landed after the day was built. A day without a rollup
     * is not behind, it is missing. Only these two counts are compared, so a late outbound, scroll or download row
     * alone does not show.
     */
    public function isBehind(CarbonImmutable $day): bool
    {
        $stored = $this->stored($day);

        if ($stored === null) {
            return false;
        }

        $raw = $this->raw($day);

        return $raw['page_views'] > $stored['page_views'] || $raw['events'] > $stored['events'];
    }

    /**
     * Whether the raw rows of a day that has a daily rollup hold fewer page views or events than the rollup counted,
     * or no events at all. That is a day whose raw rows were pruned (or deleted), and rebuilding it would replace
     * good numbers with smaller ones, so it is left alone even when a late write left a few raw rows behind.
     */
    public function isShrinking(CarbonImmutable $day): bool
    {
        $stored = $this->stored($day);

        if ($stored === null) {
            return false;
        }

        $raw = $this->raw($day);

        return $raw['page_views'] < $stored['page_views']
            || $raw['events'] < $stored['events']
            || ! $this->hasRows($day);
    }

    private function hasRows(CarbonImmutable $day): bool
    {
        return AnalyticsDatabase::table('analytics_events')
            ->where('occurred_at', '>=', $day->toDateTimeString())
            ->where('occurred_at', '<', $day->addDay()->toDateTimeString())
            ->exists();
    }

    /**
     * @return array{page_views: int, events: int}|null
     */
    private function stored(CarbonImmutable $day): ?array
    {
        $row = AnalyticsRollup::query()
            ->where('period', RollupPeriod::Day)
            ->where('dimension', RollupDimension::Total)
            ->where('bucket_start', $day->toDateTimeString())
            ->first(['page_views', 'events']);

        return $row === null ? null : ['page_views' => $row->page_views, 'events' => $row->events];
    }

    /**
     * The counts the day's total row would get from the raw rows, with the same filters as the rollup builder.
     *
     * @return array{page_views: int, events: int}
     */
    private function raw(CarbonImmutable $day): array
    {
        $events = fn (array $types) => AnalyticsDatabase::table('analytics_events')
            ->whereIn('type', $types)
            ->where('is_bot', false)
            ->where('occurred_at', '>=', $day->toDateTimeString())
            ->where('occurred_at', '<', $day->addDay()->toDateTimeString());

        return [
            'page_views' => $events([EventType::PageView])->count(),
            'events' => $events([EventType::Custom, EventType::Goal])->whereNotNull('name')->count(),
        ];
    }
}
