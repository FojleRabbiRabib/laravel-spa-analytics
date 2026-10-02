<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Query;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\PlannedBucket;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\RangePlan;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Support\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class UsersCounter
{
    public function __construct(private readonly RollupReader $reader) {}

    /**
     * Distinct people over the range, never a sum across buckets.
     *
     * They are counted from the raw events wherever those still exist. Days whose raw rows were pruned can only fall
     * back to the daily rollup counts, which are summed, so `exact` is false when that fallback added anything.
     *
     * @param  array<int, PlannedBucket>  $buckets  The resolved buckets of the plan.
     * @return array{users: int, exact: bool, new: int, goalUsers: int}
     */
    public function count(RangePlan $plan, array $buckets): array
    {
        $floor = $this->rawFloor();
        $exactFrom = $floor === null || $floor->greaterThan($plan->effectiveTo) ? $plan->effectiveTo : ($floor->greaterThan($plan->from) ? $floor : $plan->from);

        $pruned = array_values(array_filter($buckets, fn (PlannedBucket $bucket): bool => $bucket->start->lessThan($exactFrom)));

        $users = $new = $goalUsers = 0;

        if ($exactFrom->lessThan($plan->effectiveTo)) {
            $users = $this->distinct($this->pageViews($exactFrom, $plan->effectiveTo));
            $new = $this->distinct($this->pageViews($exactFrom, $plan->effectiveTo)->join('analytics_sessions as s', 's.id', '=', 'e.session_id')->where('s.is_new_visitor', true));
            $goalUsers = $this->distinct(
                $this->events(EventType::Goal, $exactFrom, $plan->effectiveTo)
                    ->whereIn('e.visitor_id', $this->pageViews($exactFrom, $plan->effectiveTo)->select('e.visitor_id')),
            );
        }

        $exact = true;

        if ($pruned !== []) {
            $total = (int) $this->reader->sums($pruned, RollupDimension::Total)['visitors'];
            $users += $total;
            $new += (int) $this->reader->sums($pruned, RollupDimension::VisitorType, 'new')['visitors'];
            $goalUsers += (int) $this->reader->sums($pruned, RollupDimension::Goal)['visitors'];
            $exact = $total === 0;
        }

        return ['users' => $users, 'exact' => $exact, 'new' => min($new, $users), 'goalUsers' => min($goalUsers, $users)];
    }

    /**
     * The start of the day of the oldest raw event, or null when there are none.
     */
    private function rawFloor(): ?CarbonImmutable
    {
        $oldest = DB::table('analytics_events')->min('occurred_at');

        return $oldest === null ? null : RollupPeriod::Day->start(CarbonImmutable::parse((string) $oldest, (string) config('app.timezone')));
    }

    private function pageViews(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->events(EventType::PageView, $from, $to);
    }

    private function events(EventType $type, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('analytics_events as e')
            ->where('e.type', $type)
            ->where('e.is_bot', false)
            ->where('e.occurred_at', '>=', $from->toDateTimeString())
            ->where('e.occurred_at', '<', $to->toDateTimeString());
    }

    private function distinct(Builder $query): int
    {
        return (int) $query->selectRaw('count(distinct '.Sql::exact('e.visitor_id').') as aggregate')->value('aggregate');
    }
}
