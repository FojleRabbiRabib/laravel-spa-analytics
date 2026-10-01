<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Console;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RetentionPruner;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'spa-analytics:prune';

    protected $description = 'Delete raw events and sessions older than the retention window once their days are rolled up';

    /**
     * Prune what retention_days allows and say why anything older was kept.
     */
    public function handle(RetentionPruner $pruner): int
    {
        $result = $pruner->prune(CarbonImmutable::now());

        if (! $result['retention']) {
            $this->components->info('Retention is off (retention_days is not a positive number); nothing to prune.');

            return self::SUCCESS;
        }

        $this->components->info("Pruned {$result['events']} events and {$result['sessions']} sessions.");

        if ($result['blockedDay'] !== null) {
            $day = $result['blockedDay']->toDateString();

            $this->components->warn("Day {$day} has no rollup yet, so it and everything after it was kept. Run: php artisan spa-analytics:rollup --since={$day}");
        }

        return self::SUCCESS;
    }
}
