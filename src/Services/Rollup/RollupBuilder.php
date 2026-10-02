<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Support\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class RollupBuilder
{
    private const INSERT_CHUNK = 50;

    /**
     * Recompute every row of one bucket from the raw events and sessions and replace what was stored.
     *
     * The bucket covers start (inclusive) to the end of the period (exclusive). Bots are left out. The bucket
     * always gets a total row, even without traffic, which marks it as rolled up.
     * The caller is expected to hold the rollup lock.
     */
    public function rollup(RollupPeriod $period, CarbonImmutable $start): void
    {
        $start = $period->start($start);
        $rows = $this->rows($start->toDateTimeString(), $period->end($start)->toDateTimeString());

        DB::transaction(function () use ($period, $start, $rows): void {
            AnalyticsRollup::query()
                ->where('period', $period)
                ->where('bucket_start', $start->toDateTimeString())
                ->delete();

            $records = [];

            foreach ($rows as $key => $metrics) {
                [$dimension, $value] = RollupDimension::parseKey($key);
                $value = mb_substr($value, 0, 512);

                $records[] = [
                    'period' => $period,
                    'bucket_start' => $start->toDateTimeString(),
                    'dimension' => $dimension,
                    'value' => $value,
                    'value_hash' => sha1($value),
                    ...$metrics,
                ];
            }

            foreach (array_chunk($records, self::INSERT_CHUNK) as $chunk) {
                AnalyticsRollup::query()->insert($chunk);
            }
        });
    }

    /**
     * @return array<string, array{page_views: int, visitors: int, sessions: int, bounces: int, duration_seconds: int, events: int, revenue: float}>
     */
    private function rows(string $from, string $to): array
    {
        $rows = [];

        $this->add($rows, RollupDimension::Total, '');

        $this->addPageViews($rows, $from, $to);
        $this->addSessions($rows, $from, $to);
        $this->addNamedEvents($rows, $from, $to);

        return $rows;
    }

    /**
     * @param  array<string, array<string, int|float>>  $rows
     * @return array<string, int|float>
     */
    private function add(array &$rows, RollupDimension $dimension, string|\BackedEnum $value): array
    {
        $key = $dimension->key($value);

        $rows[$key] ??= [
            'page_views' => 0,
            'visitors' => 0,
            'sessions' => 0,
            'bounces' => 0,
            'duration_seconds' => 0,
            'events' => 0,
            'revenue' => 0.0,
        ];

        return $rows[$key];
    }

    /**
     * @param  array<string, array<string, int|float>>  $rows
     */
    private function set(array &$rows, RollupDimension $dimension, string|\BackedEnum $value, string $metric, int|float $amount): void
    {
        $this->add($rows, $dimension, $value);
        $rows[$dimension->key($value)][$metric] = $amount;
    }

    /**
     * @param  array<string, array<string, int|float>>  $rows
     */
    private function addPageViews(array &$rows, string $from, string $to): void
    {
        $base = fn () => DB::table('analytics_events as e')
            ->where('e.type', EventType::PageView)
            ->where('e.is_bot', false)
            ->where('e.occurred_at', '>=', $from)
            ->where('e.occurred_at', '<', $to);

        $total = $base()->selectRaw('count(*) as page_views, count(distinct '.Sql::exact('e.visitor_id').') as visitors')->first();
        $this->set($rows, RollupDimension::Total, '', 'page_views', (int) $total->page_views);
        $this->set($rows, RollupDimension::Total, '', 'visitors', (int) $total->visitors);

        $this->addGroupedPageViews($rows, RollupDimension::Path, $base()->whereNotNull('e.path')->groupBy(DB::raw(Sql::exact('e.path')))->selectRaw(Sql::exact('e.path').' as value'));

        foreach (RollupDimension::SESSION_COLUMNS as $column => $dimension) {
            $this->addGroupedPageViews(
                $rows,
                $dimension,
                $base()->join('analytics_sessions as s', 's.id', '=', 'e.session_id')
                    ->whereNotNull('s.'.$column)
                    ->groupBy(DB::raw(Sql::exact('s.'.$column)))
                    ->selectRaw(Sql::exact('s.'.$column).' as value'),
            );
        }

        $this->addGroupedPageViews($rows, RollupDimension::Status, $base()->whereNotNull('e.status')->groupBy('e.status')->selectRaw('e.status as value'));

        $errors = $base()->where('e.status', '>=', 400)->whereNotNull('e.path')
            ->groupBy('e.status', DB::raw(Sql::exact('e.path')))
            ->selectRaw('e.status as status, '.Sql::exact('e.path').' as path, count(*) as page_views, count(distinct '.Sql::exact('e.visitor_id').') as visitors')
            ->get();

        foreach ($errors as $group) {
            $value = RollupDimension::errorPathValue((int) $group->status, (string) $group->path);

            $this->set($rows, RollupDimension::ErrorPath, $value, 'page_views', (int) $group->page_views);
            $this->set($rows, RollupDimension::ErrorPath, $value, 'visitors', (int) $group->visitors);
        }

        $this->addGroupedPageViews(
            $rows,
            RollupDimension::VisitorType,
            $base()->join('analytics_sessions as s', 's.id', '=', 'e.session_id')
                ->groupBy('s.is_new_visitor')
                ->selectRaw('s.is_new_visitor as value'),
        );
    }

    /**
     * @param  array<string, array<string, int|float>>  $rows
     */
    private function addGroupedPageViews(array &$rows, RollupDimension $dimension, Builder $query): void
    {
        foreach ($query->selectRaw('count(*) as page_views, count(distinct '.Sql::exact('e.visitor_id').') as visitors')->get() as $group) {
            $value = $dimension->storedValue($group->value);

            $this->set($rows, $dimension, $value, 'page_views', (int) $group->page_views);
            $this->set($rows, $dimension, $value, 'visitors', (int) $group->visitors);
        }
    }

    /**
     * Sessions are summed in one pass over the sessions that started in the bucket, because the duration of a
     * session has no portable SQL expression.
     *
     * @param  array<string, array<string, int|float>>  $rows
     */
    private function addSessions(array &$rows, string $from, string $to): void
    {
        AnalyticsSession::query()
            ->select(['id', 'started_at', 'last_seen_at', 'page_views', 'entry_path', 'exit_path', 'is_new_visitor', ...array_keys(RollupDimension::SESSION_COLUMNS)])
            ->where('is_bot', false)
            ->where('started_at', '>=', $from)
            ->where('started_at', '<', $to)
            ->lazyById(1000)
            ->each(function (AnalyticsSession $session) use (&$rows): void {
                $duration = max(0, $session->last_seen_at->getTimestamp() - $session->started_at->getTimestamp());
                $bounce = $session->page_views === 1 ? 1 : 0;

                $targets = [[RollupDimension::Total, ''], [RollupDimension::Path, $session->entry_path], [RollupDimension::ExitPath, $session->exit_path]];
                $targets[] = [RollupDimension::ReferrerType, $session->referrer_type];
                $targets[] = [RollupDimension::VisitorType, $session->is_new_visitor ? 'new' : 'returning'];

                foreach (RollupDimension::SESSION_COLUMNS as $column => $dimension) {
                    if ($dimension->is(RollupDimension::ReferrerType) || $session->{$column} === null) {
                        continue;
                    }

                    $targets[] = [$dimension, $session->{$column}];
                }

                foreach ($targets as [$dimension, $value]) {
                    $this->add($rows, $dimension, $value);
                    $key = $dimension->key($value);
                    $rows[$key]['sessions']++;
                    $rows[$key]['bounces'] += $bounce;
                    $rows[$key]['duration_seconds'] += $duration;
                }
            });
    }

    /**
     * @param  array<string, array<string, int|float>>  $rows
     */
    private function addNamedEvents(array &$rows, string $from, string $to): void
    {
        $events = DB::table('analytics_events')
            ->whereIn('type', [EventType::Custom, EventType::Goal])
            ->where('is_bot', false)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->whereNotNull('name')
            ->groupBy('type', DB::raw(Sql::exact('name')))
            ->selectRaw('type, '.Sql::exact('name').' as name, count(*) as events, count(distinct '.Sql::exact('visitor_id').') as visitors, coalesce(sum(value), 0) as revenue')
            ->get();

        $totalEvents = 0;
        $totalRevenue = 0.0;

        foreach ($events as $group) {
            $dimension = EventType::coerce($group->type)->is(EventType::Goal) ? RollupDimension::Goal : RollupDimension::Event;

            $this->set($rows, $dimension, (string) $group->name, 'events', (int) $group->events);
            $this->set($rows, $dimension, (string) $group->name, 'visitors', (int) $group->visitors);
            $this->set($rows, $dimension, (string) $group->name, 'revenue', round((float) $group->revenue, 2));

            $totalEvents += (int) $group->events;
            $totalRevenue += (float) $group->revenue;
        }

        $this->set($rows, RollupDimension::Total, '', 'events', $totalEvents);
        $this->set($rows, RollupDimension::Total, '', 'revenue', round($totalRevenue, 2));

        $this->addClientEvents($rows, $from, $to, EventType::OutboundClick, RollupDimension::OutboundHost, 'target_host', true);
        $this->addClientEvents($rows, $from, $to, EventType::ScrollDepth, RollupDimension::ScrollDepth, 'scroll_percent', false);
    }

    /**
     * Outbound clicks by target host and scroll depth milestones, counted apart from the named events so the
     * total events number keeps meaning custom events and goals.
     *
     * @param  array<string, array<string, int|float>>  $rows
     */
    private function addClientEvents(array &$rows, string $from, string $to, EventType $type, RollupDimension $dimension, string $column, bool $text): void
    {
        $expression = $text ? Sql::exact($column) : $column;

        $groups = DB::table('analytics_events')
            ->where('type', $type)
            ->where('is_bot', false)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->whereNotNull($column)
            ->groupBy(DB::raw($expression))
            ->selectRaw($expression.' as value, count(*) as events, count(distinct '.Sql::exact('visitor_id').') as visitors')
            ->get();

        foreach ($groups as $group) {
            $value = (string) $group->value;

            $this->set($rows, $dimension, $value, 'events', (int) $group->events);
            $this->set($rows, $dimension, $value, 'visitors', (int) $group->visitors);
        }
    }
}
