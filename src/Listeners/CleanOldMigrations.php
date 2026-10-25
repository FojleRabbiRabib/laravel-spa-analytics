<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Listeners;

use FojleRabbiRabib\LaravelSpaAnalytics\Services\Migrations\OldPatchMigrations;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Events\VendorTagPublished;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CleanOldMigrations
{
    private const COMMANDS = ['migrate', 'migrate:fresh', 'migrate:refresh'];

    /**
     * Delete the package's old update migration files before migrate collects the pending ones, which is why this
     * listens to the command start and not to the migration events (they fire after the file list is built). The
     * nested migrate that migrate:fresh runs starts no console event, so the top-level command is the one matched.
     */
    public function whenCommandStarts(CommandStarting $event): void
    {
        if (! in_array($event->command, self::COMMANDS, true) || $event->input->hasParameterOption('--pretend')) {
            return;
        }

        $deleted = $this->clean();

        if ($deleted !== []) {
            $event->output->writeln('<comment>spa-analytics: deleted '.count($deleted).' old migration file(s) replaced by '.OldPatchMigrations::CURRENT.': '.implode(', ', $deleted).'</comment>');
        }
    }

    /**
     * Delete them after a publish of this package's tags (or of a provider, which has no tag), so the old files leave the
     * working tree, and its git history, in the same step that adds the current migrations.
     */
    public function whenPublished(VendorTagPublished $event): void
    {
        if ($event->tag === null || Str::startsWith((string) $event->tag, 'spa-analytics')) {
            $this->clean();
        }
    }

    /**
     * The service is resolved here, not in the constructor, so a command that is not a migrate (package:discover during
     * composer install, schedule:run, queue workers) does not even build it.
     *
     * @return array<int, string>
     */
    private function clean(): array
    {
        try {
            return app(OldPatchMigrations::class)->delete();
        } catch (\Throwable $e) {
            Log::warning('spa-analytics: old migration cleanup failed', ['exception' => $e::class, 'code' => $e->getCode()]);

            return [];
        }
    }
}
