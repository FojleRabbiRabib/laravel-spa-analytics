<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Query;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\FunnelStep;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\RangePlan;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\FunnelStepType;
use FojleRabbiRabib\LaravelSpaAnalytics\Support\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FunnelCounter
{
    /**
     * How many visitors reached each step, having completed the steps before it in order.
     *
     * The raw events that can satisfy any step are read in one pass, ordered by visitor and then by time (events in
     * the same second page views first, because a request's page view is recorded after its controller has fired
     * any goal, then by when they were stored), and each visitor moves through the steps in PHP. One event moves a
     * visitor one step at most. The SQL only narrows the rows; PHP decides which step an event satisfies, so
     * matching is case-exact on every engine.
     *
     * @param  array<int, FunnelStep>  $steps
     * @return array<int, int> Users per step, in step order.
     */
    public function count(RangePlan $plan, array $steps, CarbonImmutable $from): array
    {
        $counts = array_fill(0, count($steps), 0);

        if ($from->greaterThanOrEqualTo($plan->effectiveTo)) {
            return $counts;
        }

        $events = DB::table('analytics_events')
            ->where('is_bot', false)
            ->where('occurred_at', '>=', $from->toDateTimeString())
            ->where('occurred_at', '<', $plan->effectiveTo->toDateTimeString())
            ->where(fn (Builder $query) => $this->matchingAnyStep($query, $steps))
            ->orderByRaw(Sql::exact('visitor_id'))
            ->orderBy('occurred_at')
            ->orderByRaw('case when type = ? then 0 else 1 end', [EventType::PageView->value])
            ->orderBy('id')
            ->select(['visitor_id', 'type', 'path', 'name'])
            ->cursor();

        $visitor = null;
        $next = 0;

        foreach ($events as $event) {
            if ($event->visitor_id !== $visitor) {
                $visitor = $event->visitor_id;
                $next = 0;
            }

            if ($next < count($steps) && $steps[$next]->matches((string) $event->type, $event->path, $event->name)) {
                $counts[$next]++;
                $next++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<int, FunnelStep>  $steps
     */
    private function matchingAnyStep(Builder $query, array $steps): void
    {
        foreach ($steps as $step) {
            $query->orWhere(function (Builder $inner) use ($step): void {
                $inner->where('type', $step->type->eventType());

                match ($step->type) {
                    FunnelStepType::Path => $inner->whereRaw(Sql::exact('path').' = ?', [$step->value]),
                    FunnelStepType::PathPrefix => $inner->whereRaw(Sql::exact('substr(path, 1, '.mb_strlen($step->value).')').' = ?', [$step->value]),
                    FunnelStepType::Event, FunnelStepType::Goal => $inner->whereRaw(Sql::exact('name').' = ?', [$step->value]),
                };
            });
        }
    }
}
