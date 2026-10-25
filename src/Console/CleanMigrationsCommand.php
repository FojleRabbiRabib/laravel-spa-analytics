<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Console;

use FojleRabbiRabib\LaravelSpaAnalytics\Services\Migrations\OldPatchMigrations;
use Illuminate\Console\Command;

class CleanMigrationsCommand extends Command
{
    protected $signature = 'spa-analytics:clean-migrations';

    protected $description = 'Delete the old update migration files of earlier package versions';

    /**
     * The same cleanup that runs by itself before migrate and after vendor:publish, for apps that want to run it on
     * demand. Files are deleted only once the current update migration is published; the migrations table is never
     * touched.
     */
    public function handle(OldPatchMigrations $old): int
    {
        $found = $old->published();

        if ($found === []) {
            $this->components->info('No old migration files found.');

            return self::SUCCESS;
        }

        if (! $old->currentIsPublished()) {
            $this->components->warn('Nothing deleted: publish the current migrations first (php artisan vendor:publish --tag=spa-analytics-migrations).');

            return self::SUCCESS;
        }

        $deleted = $old->delete();

        $this->components->bulletList($deleted);
        $this->components->info(count($deleted).' old migration file(s) deleted.');

        if (count($deleted) < count($found)) {
            $this->components->warn(count($found) - count($deleted).' file(s) could not be deleted; check the file permissions.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
