<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Query;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class StatsService
{
    public function __construct(
        private readonly RangePlanner $planner,
        private readonly RollupCoverage $coverage,
        private readonly RollupReader $reader,
        private readonly UsersCounter $users,
    ) {}

    /**
     * A report over the range, read in the app timezone and snapped outward to whole hours.
     */
    public function between(CarbonInterface $from, CarbonInterface $to): StatsReport
    {
        $timezone = (string) config('app.timezone');

        return new StatsReport(
            CarbonImmutable::instance($from)->setTimezone($timezone),
            CarbonImmutable::instance($to)->setTimezone($timezone),
            $this->planner,
            $this->coverage,
            $this->reader,
            $this->users,
        );
    }

    /**
     * A report over the complete days before today, which is left out.
     */
    public function lastDays(int $days): StatsReport
    {
        $today = CarbonImmutable::now((string) config('app.timezone'))->startOfDay();

        return $this->between($today->subDays(max(1, $days)), $today);
    }
}
