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
        $exactFrom = $this->exactFrom($plan);

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
     * Distinct people for each of the given values of a dimension, with the same exact-or-fallback rule as count().
     *
     * Dimensions that describe a session through its page views (and the event and goal names) count visitors who
     * viewed or triggered the value. Exit paths count visitors whose session ended there; the daily rollups hold no
     * visitors for those, so any pruned day with such sessions only makes the row inexact.
     *
     * @param  array<int, PlannedBucket>  $buckets  The resolved buckets of the plan.
     *                                              With $viewersOnly, event and goal rows count only visitors who also viewed a page in the range, which is how
     *                                              count() counts goal users, so a goal's conversion can never exceed the overall one.
     * @param  array<int, string>  $values
     * @return array<string, array{users: int, exact: bool}>
     */
    public function forValues(RangePlan $plan, array $buckets, RollupDimension $dimension, array $values, bool $viewersOnly = false): array
    {
        $found = [];

        if ($values === []) {
            return $found;
        }

        foreach ($values as $value) {
            $found[$value] = ['users' => 0, 'exact' => true];
        }

        $exactFrom = $this->exactFrom($plan);

        if ($exactFrom->lessThan($plan->effectiveTo) && $dimension->is(RollupDimension::ErrorPath)) {
            foreach ($this->errorPathUsers($values, $exactFrom, $plan->effectiveTo) as $value => $users) {
                $found[$value]['users'] += $users;
            }
        } elseif ($exactFrom->lessThan($plan->effectiveTo)) {
            [$query, $column, $visitor] = $this->source($dimension, $exactFrom, $plan->effectiveTo);
            $integer = $dimension->is(RollupDimension::ScrollDepth) || $dimension->is(RollupDimension::Status);
            $numeric = $dimension->is(RollupDimension::VisitorType) || $integer;
            $expression = $numeric ? $column : Sql::exact($column);

            if ($viewersOnly) {
                $query->whereIn('e.visitor_id', $this->pageViews($exactFrom, $plan->effectiveTo)->select('e.visitor_id'));
            }

            if (! $dimension->is(RollupDimension::VisitorType)) {
                $bindings = $integer ? array_map(intval(...), $values) : $values;

                $query->whereRaw($expression.' in ('.implode(', ', array_fill(0, count($values), '?')).')', $bindings);
            }

            $rows = $query->groupBy(DB::raw($expression))
                ->selectRaw($expression.' as value, count(distinct '.Sql::exact($visitor).') as users')
                ->get();

            foreach ($rows as $row) {
                $value = $dimension->storedValue($row->value);

                if (isset($found[$value])) {
                    $found[$value]['users'] += (int) $row->users;
                }
            }
        }

        $pruned = array_values(array_filter($buckets, fn (PlannedBucket $bucket): bool => $bucket->start->lessThan($exactFrom)));

        if ($pruned !== []) {
            $metric = $dimension->is(RollupDimension::ExitPath) ? 'sessions' : 'visitors';

            foreach ($this->reader->grouped($pruned, $dimension, $metric, null, $values) as $row) {
                $found[$row['value']]['users'] += $dimension->is(RollupDimension::ExitPath) ? 0 : (int) $row['visitors'];
                $found[$row['value']]['exact'] = (int) $row[$metric] === 0;
            }
        }

        return $found;
    }

    /**
     * Distinct people for error path values, which are a status and a path, so the rows are matched on both parts.
     *
     * @param  array<int, string>  $values
     * @return array<string, int>
     */
    private function errorPathUsers(array $values, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $statuses = $paths = [];

        foreach ($values as $value) {
            [$status, $path] = array_pad(explode(' ', $value, 2), 2, '');
            $statuses[] = (int) $status;
            $paths[] = $path;
        }

        $rows = $this->pageViews($from, $to)
            ->whereIn('e.status', array_values(array_unique($statuses)))
            ->whereRaw(Sql::exact('e.path').' in ('.implode(', ', array_fill(0, count($paths), '?')).')', $paths)
            ->groupBy('e.status', DB::raw(Sql::exact('e.path')))
            ->selectRaw('e.status as status, '.Sql::exact('e.path').' as path, count(distinct '.Sql::exact('e.visitor_id').') as users')
            ->get();

        $users = [];

        foreach ($rows as $row) {
            $value = RollupDimension::errorPathValue((int) $row->status, (string) $row->path);

            if (in_array($value, $values, true)) {
                $users[$value] = (int) $row->users;
            }
        }

        return $users;
    }

    /**
     * The raw query behind a dimension, its value column and the visitor column to count.
     *
     * @return array{Builder, string, string}
     */
    private function source(RollupDimension $dimension, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($dimension->is(RollupDimension::ExitPath)) {
            $sessions = DB::table('analytics_sessions as s')
                ->where('s.is_bot', false)
                ->where('s.started_at', '>=', $from->toDateTimeString())
                ->where('s.started_at', '<', $to->toDateTimeString());

            return [$sessions, 's.exit_path', 's.visitor_id'];
        }

        return match ($dimension) {
            RollupDimension::Path => [$this->pageViews($from, $to), 'e.path', 'e.visitor_id'],
            RollupDimension::Event => [$this->events(EventType::Custom, $from, $to), 'e.name', 'e.visitor_id'],
            RollupDimension::Goal => [$this->events(EventType::Goal, $from, $to), 'e.name', 'e.visitor_id'],
            RollupDimension::OutboundHost => [$this->events(EventType::OutboundClick, $from, $to), 'e.target_host', 'e.visitor_id'],
            RollupDimension::ScrollDepth => [$this->events(EventType::ScrollDepth, $from, $to), 'e.scroll_percent', 'e.visitor_id'],
            RollupDimension::Status => [$this->pageViews($from, $to), 'e.status', 'e.visitor_id'],
            RollupDimension::VisitorType => [$this->sessionPageViews($from, $to), 's.is_new_visitor', 'e.visitor_id'],
            default => [$this->sessionPageViews($from, $to), 's.'.($dimension->sessionColumn() ?? throw new \InvalidArgumentException('The dimension has no per-value users.')), 'e.visitor_id'],
        };
    }

    private function sessionPageViews(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->pageViews($from, $to)->join('analytics_sessions as s', 's.id', '=', 'e.session_id');
    }

    /**
     * Where raw events can be counted from: the start of the plan, or the first day that still has raw events when
     * the earlier ones were pruned. It is the end of the plan when there is nothing to count.
     */
    public function exactFrom(RangePlan $plan): CarbonImmutable
    {
        $floor = $this->rawFloor();

        return $floor === null || $floor->greaterThan($plan->effectiveTo) ? $plan->effectiveTo : ($floor->greaterThan($plan->from) ? $floor : $plan->from);
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
