<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Migrations;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\File;

class OldPatchMigrations
{
    /**
     * The update migration that brings any earlier version to the current shape. Rename it, and add the old name to
     * NAMES, whenever the database changes again.
     */
    public const CURRENT = 'update_analytics_tables_rev_2';

    /**
     * Update migrations of earlier versions that the current one replaces: the seven separate files of versions up to
     * 0.7.0, then every superseded revision.
     */
    public const NAMES = [
        'update_analytics_events_table_for_custom_events',
        'update_analytics_sessions_table_for_audience',
        'update_analytics_events_table_for_client_events',
        'update_analytics_events_table_for_downloads',
        'update_analytics_sessions_table_for_viewport',
        'update_analytics_events_table_for_engagement',
        'update_analytics_rollups_table_for_engagement',
    ];

    public function __construct(private readonly Migrator $migrator) {}

    /**
     * The old update migration files in the app, as file path => migration name. Only exact names of the package's own
     * old migrations match, in the app's migration folders and any registered path outside vendor/.
     *
     * @return array<string, string>
     */
    public function published(): array
    {
        return $this->find(self::NAMES);
    }

    /**
     * Whether the current update migration is published, which makes every old one redundant: it brings any version to
     * the current shape, whether the old files ran or not, so deleting them can never skip a change.
     */
    public function currentIsPublished(): bool
    {
        return $this->find([self::CURRENT]) !== [];
    }

    /**
     * Delete the old files once the current update migration is published; the migrations table is never touched, so a
     * file that comes back (for example from git) is still skipped as already run. Nothing here throws.
     *
     * @return array<int, string> The names of the files deleted.
     */
    public function delete(): array
    {
        if (! $this->currentIsPublished()) {
            return [];
        }

        $deleted = [];

        foreach ($this->published() as $file => $name) {
            if (File::delete($file)) {
                $deleted[] = $name;
            }
        }

        return $deleted;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, string>
     */
    private function find(array $names): array
    {
        $found = [];

        foreach ($this->directories() as $directory) {
            foreach (File::glob($directory.'/*.php') as $file) {
                $name = basename($file, '.php');

                if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_(.+)$/', $name, $match) === 1 && in_array($match[1], $names, true)) {
                    $found[(string) realpath($file)] = $name;
                }
            }
        }

        return $found;
    }

    /**
     * @return array<int, string>
     */
    private function directories(): array
    {
        $vendor = (string) realpath(base_path('vendor'));
        $app = realpath(database_path('migrations'));
        $directories = $app === false ? [] : [$app => $app];

        foreach ($this->migrator->paths() as $path) {
            $real = realpath($path);

            if ($real === false || ($vendor !== '' && str_starts_with($real.DIRECTORY_SEPARATOR, $vendor.DIRECTORY_SEPARATOR))) {
                continue;
            }

            $directories[$real] = $real;
        }

        return array_values($directories);
    }
}
