<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Query;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\Funnel;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\FunnelStep;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\FunnelStepResult;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\GoalRow;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\PlannedBucket;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\RangePlan;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\SeriesPoint;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\Summary;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\TopRow;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\DimensionSettings;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupBuilder;
use FojleRabbiRabib\LaravelSpaAnalytics\Support\AnalyticsDatabase;

class StatsReport
{
    /**
     * @var ?array{plan: RangePlan, buckets: array<int, PlannedBucket>, incomplete: bool, through: CarbonImmutable, stored: ?CarbonImmutable, tail: LiveTail}
     */
    private ?array $range = null;

    private bool $resolved = false;

    private bool $behind = false;

    public function __construct(
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly RangePlanner $planner,
        private readonly RollupCoverage $coverage,
        private readonly RollupReader $reader,
        private readonly UsersCounter $users,
        private readonly FunnelCounter $funnels,
        private readonly DimensionSettings $settings,
        private readonly RollupBuilder $builder,
        private readonly bool $live,
    ) {}

    /**
     * The same report with the hours that have not been rolled up yet added, worked out from the raw events when a
     * method is called. Nothing is stored, and the live part reaches back at most stats.live_max_hours.
     */
    public function withLive(): self
    {
        return $this->liveAs(true);
    }

    /**
     * The same report from the stored rollups only, which cover completed hours.
     */
    public function withoutLive(): self
    {
        return $this->liveAs(false);
    }

    /**
     * The headline numbers of the range.
     */
    public function summary(): Summary
    {
        $range = $this->range();

        if ($range === null) {
            return new Summary(0, 0, true, 0, 0, 0, 0, 0.0, 0.0, 0, 0, '0.00', 0.0, null, $this->behind, 0, 0.0, 0.0);
        }

        ['plan' => $plan, 'buckets' => $buckets, 'incomplete' => $incomplete, 'through' => $through, 'tail' => $tail] = $range;

        $total = LiveTail::add($this->reader->sums($buckets, RollupDimension::Total), $tail->sums(RollupDimension::Total));
        $goals = LiveTail::add($this->reader->sums($buckets, RollupDimension::Goal), $tail->sums(RollupDimension::Goal));
        $people = $this->users->count($plan, $buckets);

        $sessions = (int) $total['sessions'];

        return new Summary(
            pageViews: (int) $total['page_views'],
            users: $people['users'],
            usersExact: $people['exact'],
            newUsers: $people['new'],
            returningUsers: $people['users'] - $people['new'],
            sessions: $sessions,
            bounces: (int) $total['bounces'],
            bounceRate: $sessions > 0 ? (int) $total['bounces'] / $sessions : 0.0,
            avgSessionDuration: $sessions > 0 ? (int) $total['duration_seconds'] / $sessions : 0.0,
            events: (int) $total['events'],
            goalCompletions: (int) $goals['events'],
            revenue: (string) $total['revenue'],
            conversionRate: $people['users'] > 0 ? min(1.0, $people['goalUsers'] / $people['users']) : 0.0,
            through: $through,
            incomplete: $incomplete,
            engagedSeconds: (int) $total['engaged_seconds'],
            avgEngagementPerUser: $people['users'] > 0 ? (int) $total['engaged_seconds'] / $people['users'] : 0.0,
            avgEngagementPerSession: $sessions > 0 ? (int) $total['engaged_seconds'] / $sessions : 0.0,
        );
    }

    /**
     * One point per hour or day of the range, with empty buckets filled in as zeros.
     *
     * @return array<int, SeriesPoint>
     */
    public function timeseries(RollupPeriod $period = RollupPeriod::Day): array
    {
        $range = $this->range();

        if ($range === null) {
            return [];
        }

        $plan = $range['plan'];
        $storedTo = $range['stored'] === null ? $plan->from : min($plan->effectiveTo, $range['stored']);
        $hours = $this->reader->totalsBetween(RollupPeriod::Hour, $plan->from, $storedTo);

        foreach ($range['tail']->totals() as $start => $metrics) {
            $hours[$start] = isset($hours[$start]) ? LiveTail::add($hours[$start], $metrics) : $metrics;
        }

        $days = $period->is(RollupPeriod::Day) ? $this->reader->totalsBetween(RollupPeriod::Day, RollupPeriod::Day->start($plan->from), $storedTo) : [];
        $points = [];

        for ($start = $period->start($plan->from); $start->lessThan($plan->effectiveTo); $start = $period->end($start)) {
            $end = $period->end($start);
            $whole = $start->greaterThanOrEqualTo($plan->from) && $end->lessThanOrEqualTo($plan->effectiveTo);
            $key = $start->toDateTimeString();

            if ($period->is(RollupPeriod::Hour)) {
                $metrics = $hours[$key] ?? null;
            } elseif ($whole && isset($days[$key])) {
                $metrics = $days[$key];
            } else {
                $metrics = $this->sumHours($hours, max($start, $plan->from), min($end, $plan->effectiveTo));
            }

            $metrics ??= $this->sumHours([], $start, $end);

            $points[] = new SeriesPoint(
                start: $start,
                pageViews: (int) $metrics['page_views'],
                visitors: (int) $metrics['visitors'],
                sessions: (int) $metrics['sessions'],
                bounces: (int) $metrics['bounces'],
                events: (int) $metrics['events'],
                revenue: (string) $metrics['revenue'],
            );
        }

        return $points;
    }

    /**
     * The best values of a dimension, with users counted for those rows only.
     *
     * Paths, languages, statuses and error paths are ordered by page views, events, goals, outbound hosts, scroll depth, downloads and file extensions by events, and everything else by sessions.
     *
     * @return array<int, TopRow>
     *
     * @throws \InvalidArgumentException For the total dimension, which has no values to rank, and for a dimension
     *                                   turned off in rollups.disabled_dimensions.
     */
    public function top(RollupDimension $dimension, int $limit = 10): array
    {
        if ($dimension->is(RollupDimension::Total)) {
            throw new \InvalidArgumentException('The total dimension has no values to rank.');
        }

        if (! $this->settings->isEnabled($dimension)) {
            throw new \InvalidArgumentException("The {$dimension->value} dimension is turned off in rollups.disabled_dimensions.");
        }

        $range = $this->range();

        if ($range === null) {
            return [];
        }

        ['plan' => $plan, 'buckets' => $buckets, 'tail' => $tail] = $range;

        $orderBy = match ($dimension) {
            RollupDimension::Path, RollupDimension::Language, RollupDimension::Status, RollupDimension::ErrorPath => 'page_views',
            RollupDimension::Event, RollupDimension::Goal, RollupDimension::OutboundHost, RollupDimension::ScrollDepth, RollupDimension::Download, RollupDimension::FileExtension => 'events',
            default => 'sessions',
        };

        $limit = max(1, min($limit, 1000));
        $rows = $this->ranked($buckets, $tail, $dimension, $orderBy, $limit);
        $users = $this->users->forValues($plan, $buckets, $dimension, array_column($rows, 'value'));

        return array_map(function (array $row) use ($users): TopRow {
            $sessions = (int) $row['sessions'];

            return new TopRow(
                value: (string) $row['value'],
                pageViews: (int) $row['page_views'],
                users: $users[$row['value']]['users'],
                usersExact: $users[$row['value']]['exact'],
                sessions: $sessions,
                bounces: (int) $row['bounces'],
                bounceRate: $sessions > 0 ? (int) $row['bounces'] / $sessions : 0.0,
                avgSessionDuration: $sessions > 0 ? (int) $row['duration_seconds'] / $sessions : 0.0,
                events: (int) $row['events'],
                revenue: (string) $row['revenue'],
                engagedSeconds: (int) $row['engaged_seconds'],
                avgEngagement: $users[$row['value']]['users'] > 0 ? (int) $row['engaged_seconds'] / $users[$row['value']]['users'] : 0.0,
            );
        }, $rows);
    }

    /**
     * Each goal with its completions, revenue, distinct completers and conversion rate, most completed first.
     *
     * @return array<int, GoalRow>
     */
    public function goals(int $limit = 50): array
    {
        $range = $this->range();

        if ($range === null) {
            return [];
        }

        ['plan' => $plan, 'buckets' => $buckets, 'tail' => $tail] = $range;

        $rows = $this->ranked($buckets, $tail, RollupDimension::Goal, 'events', max(1, min($limit, 1000)));
        $names = array_column($rows, 'value');
        $completers = $this->users->forValues($plan, $buckets, RollupDimension::Goal, $names);
        $converters = $this->users->forValues($plan, $buckets, RollupDimension::Goal, $names, viewersOnly: true);
        $people = $this->users->count($plan, $buckets);

        return array_map(fn (array $row): GoalRow => new GoalRow(
            name: (string) $row['value'],
            completions: (int) $row['events'],
            users: $completers[$row['value']]['users'],
            usersExact: $completers[$row['value']]['exact'],
            revenue: (string) $row['revenue'],
            conversionRate: $people['users'] > 0 ? min($converters[$row['value']]['users'], $people['goalUsers']) / $people['users'] : 0.0,
        ), $rows);
    }

    /**
     * How many visitors got through each step in order, with page, event and goal steps.
     *
     * A visitor reaches a step only after completing the steps before it somewhere in the range, however many days
     * apart, and one event moves them one step. Funnels read raw events, so days whose raw rows were pruned are not
     * counted: `coveredFrom` says where counting starts and `complete` is false then.
     *
     * @param  array<int, FunnelStep>  $steps  Between 2 and 10 steps.
     *
     * @throws \InvalidArgumentException For fewer than 2 or more than 10 steps.
     */
    public function funnel(array $steps): Funnel
    {
        $steps = array_values($steps);

        if (count($steps) < 2 || count($steps) > 10) {
            throw new \InvalidArgumentException('A funnel needs between 2 and 10 steps.');
        }

        $range = $this->range();
        $counts = array_fill(0, count($steps), 0);
        $coveredFrom = null;
        $complete = true;

        if ($range !== null) {
            ['plan' => $plan, 'buckets' => $buckets] = $range;
            $from = $this->users->exactFrom($plan);
            $counts = $this->funnels->count($plan, $steps, $from);

            $before = array_values(array_filter($buckets, fn (PlannedBucket $bucket): bool => $bucket->start->lessThan($from)));
            $earlier = $before === [] ? ['page_views' => 0, 'events' => 0] : $this->reader->sums($before, RollupDimension::Total);
            $complete = (int) $earlier['page_views'] + (int) $earlier['events'] === 0;
            $coveredFrom = $from->lessThan($plan->effectiveTo) ? ($complete ? $plan->from : $from) : null;
        }

        $results = [];

        foreach ($steps as $index => $step) {
            $results[] = new FunnelStepResult(
                type: $step->type,
                value: $step->value,
                label: $step->label,
                users: $counts[$index],
                fromPrevious: $index === 0 ? 1.0 : ($counts[$index - 1] > 0 ? $counts[$index] / $counts[$index - 1] : 0.0),
                fromFirst: $index === 0 ? 1.0 : ($counts[0] > 0 ? $counts[$index] / $counts[0] : 0.0),
            );
        }

        return new Funnel($results, $counts[0] > 0 ? $counts[count($counts) - 1] / $counts[0] : 0.0, $coveredFrom, $complete, $range['through'] ?? null);
    }

    private function liveAs(bool $live): self
    {
        return new self($this->from, $this->to, $this->planner, $this->coverage, $this->reader, $this->users, $this->funnels, $this->settings, $this->builder, $live);
    }

    /**
     * What the report reads, worked out once per report: the stored buckets, the live tail and the plan whose raw
     * window reaches the end of the tail. The rolled-up end is read once and used for both parts, so an hour that is
     * rolled up in the meantime is neither counted twice nor missed.
     *
     * The live part starts where the stored one ends (before any rollup has run, at the hour of the oldest raw event).
     * It is the running hour and up to stats.live_max_hours earlier hours; when more would have to be read, none of it
     * is: counts, users and funnels then all stop at the stored end, and the result is marked incomplete. Null means
     * there is nothing to read.
     *
     * @return ?array{plan: RangePlan, buckets: array<int, PlannedBucket>, incomplete: bool, through: CarbonImmutable, stored: ?CarbonImmutable, tail: LiveTail}
     */
    private function range(): ?array
    {
        if ($this->resolved) {
            return $this->range;
        }

        $this->resolved = true;
        $stored = $this->coverage->through();

        if ($stored === null && (! $this->live || $this->from->greaterThanOrEqualTo($this->to))) {
            return null;
        }

        $base = $stored ?? RollupPeriod::Hour->start($this->from);
        $plan = $this->planner->plan($this->from, $this->to, $base);
        ['buckets' => $buckets, 'incomplete' => $incomplete] = $this->reader->resolve($plan);
        $through = $stored;
        $tail = new LiveTail;

        if ($this->live) {
            $timezone = (string) config('app.timezone');
            $now = CarbonImmutable::now($timezone);
            $end = $now->lessThan($plan->to) ? $now : $plan->to;
            $start = max($base, $plan->from);

            if ($stored === null) {
                $oldest = AnalyticsDatabase::table('analytics_events')->min('occurred_at');
                $start = $oldest === null ? $end : max($start, RollupPeriod::Hour->start(CarbonImmutable::parse((string) $oldest, $timezone)));
            }

            if ($start->lessThan($end)) {
                if ($start->lessThan(RollupPeriod::Hour->start($now->subHours(max(1, (int) config('spa-analytics.stats.live_max_hours')))))) {
                    $incomplete = true;
                    $this->behind = $stored === null;
                } else {
                    $tail = $this->tail($start, $end);
                    $plan = new RangePlan($plan->from, $plan->to, $plan->through, $end, $plan->buckets);
                    $through = $end;
                }
            }
        }

        if ($through === null) {
            return null;
        }

        return $this->range = ['plan' => $plan, 'buckets' => $buckets, 'incomplete' => $incomplete, 'through' => $through, 'stored' => $stored, 'tail' => $tail];
    }

    private function tail(CarbonImmutable $start, CarbonImmutable $end): LiveTail
    {
        $hours = [];

        for ($hour = $start; $hour->lessThan($end); $hour = $hour->addHour()) {
            $hours[$hour->toDateTimeString()] = $this->builder->preview($hour, min($hour->addHour(), $end));
        }

        return new LiveTail($hours);
    }

    /**
     * The best rows of a dimension over the stored buckets and the live tail together.
     *
     * A value that is only in the tail, or that the tail lifts into the top, has to be found in the stored buckets
     * too, so the stored rows of every value the tail has are read in addition to the stored top.
     *
     * @param  array<int, PlannedBucket>  $buckets
     * @return array<int, array<string, int|string>>
     */
    private function ranked(array $buckets, LiveTail $tail, RollupDimension $dimension, string $orderBy, int $limit): array
    {
        $rows = $this->reader->grouped($buckets, $dimension, $orderBy, $limit);
        $live = $tail->grouped($dimension);

        if ($live === []) {
            return $rows;
        }

        $merged = [];

        foreach ($rows as $row) {
            $merged[(string) $row['value']] = $row;
        }

        foreach (array_chunk(array_map('strval', array_keys($live)), 500) as $values) {
            foreach ($this->reader->grouped($buckets, $dimension, $orderBy, null, $values) as $row) {
                $merged[(string) $row['value']] = $row;
            }
        }

        foreach ($live as $value => $metrics) {
            $value = (string) $value;
            $merged[$value] = ['value' => $value, ...LiveTail::add($merged[$value] ?? [], $metrics)];
        }

        usort($merged, fn (array $a, array $b): int => $b[$orderBy] <=> $a[$orderBy] ?: strcmp((string) $a['value'], (string) $b['value']));

        return array_slice($merged, 0, $limit);
    }

    /**
     * @param  array<string, array<string, int|string>>  $hours
     * @return array<string, int|string>
     */
    private function sumHours(array $hours, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sum = ['page_views' => 0, 'visitors' => 0, 'sessions' => 0, 'bounces' => 0, 'duration_seconds' => 0, 'events' => 0, 'revenue' => 0.0, 'engaged_seconds' => 0];

        for ($hour = $from; $hour->lessThan($to); $hour = $hour->addHour()) {
            foreach ($hours[$hour->toDateTimeString()] ?? [] as $metric => $value) {
                $sum[$metric] += $metric === 'revenue' ? (float) $value : (int) $value;
            }
        }

        $sum['revenue'] = number_format(round($sum['revenue'], 2), 2, '.', '');

        return $sum;
    }
}
