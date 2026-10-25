<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Query;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\FunnelComparison;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\FunnelStep;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\GoalRow;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\GoalRowComparison;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\SeriesPointComparison;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\SummaryComparison;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\TopRow;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\TopRowComparison;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;

/**
 * Two reports side by side, the current one and the one it is compared with, answering the same questions with the
 * numbers of both and what changed. Each call reads both reports, so they follow the live setting of the report
 * they were made from.
 */
class StatsComparison
{
    public function __construct(
        private readonly StatsReport $current,
        private readonly StatsReport $previous,
    ) {}

    public function current(): StatsReport
    {
        return $this->current;
    }

    public function previous(): StatsReport
    {
        return $this->previous;
    }

    public function summary(): SummaryComparison
    {
        return SummaryComparison::between($this->current->summary(), $this->previous->summary());
    }

    /**
     * The points of the current range, each with the point at the same place of the other range (the first with the
     * first, and so on); a point without a counterpart has none.
     *
     * @return array<int, SeriesPointComparison>
     */
    public function timeseries(RollupPeriod $period = RollupPeriod::Day): array
    {
        $previous = array_values($this->previous->timeseries($period));
        $points = [];

        foreach (array_values($this->current->timeseries($period)) as $index => $point) {
            $points[] = SeriesPointComparison::between($point, $previous[$index] ?? null);
        }

        return $points;
    }

    /**
     * The best values of the current range, each with the same value's row in the other range (null when it had none).
     *
     * @return array<int, TopRowComparison>
     *
     * @throws \InvalidArgumentException As StatsReport::top() does.
     */
    public function top(RollupDimension $dimension, int $limit = 10): array
    {
        $rows = $this->current->top($dimension, $limit);
        $previous = $this->previous->topFor($dimension, array_map(fn (TopRow $row): string => $row->value, $rows));

        return array_map(fn (TopRow $row): TopRowComparison => TopRowComparison::between($row, $previous[$row->value] ?? null), $rows);
    }

    /**
     * @return array<int, GoalRowComparison>
     */
    public function goals(int $limit = 50): array
    {
        $rows = $this->current->goals($limit);
        $previous = $this->previous->goalsFor(array_map(fn (GoalRow $row): string => $row->name, $rows));

        return array_map(fn (GoalRow $row): GoalRowComparison => GoalRowComparison::between($row, $previous[$row->name] ?? null), $rows);
    }

    /**
     * @param  array<int, FunnelStep>  $steps  Between 2 and 10 steps.
     *
     * @throws \InvalidArgumentException As StatsReport::funnel() does.
     */
    public function funnel(array $steps): FunnelComparison
    {
        return FunnelComparison::between($this->current->funnel($steps), $this->previous->funnel($steps));
    }
}
