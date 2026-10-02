<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Query;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\RangePlan;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\SeriesPoint;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\Summary;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;

class StatsReport
{
    public function __construct(
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly RangePlanner $planner,
        private readonly RollupCoverage $coverage,
        private readonly RollupReader $reader,
        private readonly UsersCounter $users,
    ) {}

    /**
     * The headline numbers of the range.
     */
    public function summary(): Summary
    {
        $through = $this->coverage->through();

        if ($through === null) {
            return new Summary(0, 0, true, 0, 0, 0, 0, 0.0, 0.0, 0, 0, '0.00', 0.0, null, false);
        }

        $plan = $this->plan($through);
        ['buckets' => $buckets, 'incomplete' => $incomplete] = $this->reader->resolve($plan);

        $total = $this->reader->sums($buckets, RollupDimension::Total);
        $goals = $this->reader->sums($buckets, RollupDimension::Goal);
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
        );
    }

    /**
     * One point per hour or day of the range, with empty buckets filled in as zeros.
     *
     * @return array<int, SeriesPoint>
     */
    public function timeseries(RollupPeriod $period = RollupPeriod::Day): array
    {
        $through = $this->coverage->through();

        if ($through === null) {
            return [];
        }

        $plan = $this->plan($through);
        $hours = $this->reader->totalsBetween(RollupPeriod::Hour, $plan->from, $plan->effectiveTo);
        $days = $period->is(RollupPeriod::Day) ? $this->reader->totalsBetween(RollupPeriod::Day, RollupPeriod::Day->start($plan->from), $plan->effectiveTo) : [];
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

    private function plan(CarbonImmutable $through): RangePlan
    {
        return $this->planner->plan($this->from, $this->to, $through);
    }

    /**
     * @param  array<string, array<string, int|string>>  $hours
     * @return array<string, int|string>
     */
    private function sumHours(array $hours, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sum = ['page_views' => 0, 'visitors' => 0, 'sessions' => 0, 'bounces' => 0, 'duration_seconds' => 0, 'events' => 0, 'revenue' => 0.0];

        for ($hour = $from; $hour->lessThan($to); $hour = $hour->addHour()) {
            foreach ($hours[$hour->toDateTimeString()] ?? [] as $metric => $value) {
                $sum[$metric] += $metric === 'revenue' ? (float) $value : (int) $value;
            }
        }

        $sum['revenue'] = number_format(round($sum['revenue'], 2), 2, '.', '');

        return $sum;
    }
}
