<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Services\Migrations\OldPatchMigrations;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap\Concerns\WritesMigrationFiles;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CleanMigrationsCommandTest extends TestCase
{
    use WritesMigrationFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->removeMigrationFiles();
    }

    protected function tearDown(): void
    {
        $this->removeMigrationFiles();
        File::deleteDirectory(base_path('vendor/other-package'));

        parent::tearDown();
    }

    public function test_it_reports_when_there_is_nothing_to_clean(): void
    {
        $this->publishCurrentMigration();

        $this->artisan('spa-analytics:clean-migrations')->expectsOutputToContain('No old migration files found.')->assertExitCode(0);
    }

    public function test_it_deletes_nothing_until_the_current_update_migration_is_published(): void
    {
        $path = $this->harmlessFile('update_analytics_events_table_for_custom_events');

        $this->artisan('spa-analytics:clean-migrations')->expectsOutputToContain('publish the current migrations first')->assertExitCode(0);

        $this->assertFileExists($path);
    }

    public function test_it_deletes_the_old_files_whether_or_not_they_ran_and_leaves_the_migrations_table_alone(): void
    {
        $this->publishCurrentMigration();
        $ran = $this->harmlessFile('update_analytics_events_table_for_custom_events');
        $pending = $this->harmlessFile('update_analytics_sessions_table_for_viewport', '2026_01_01_000001');
        $this->markRan('2026_01_01_000000_update_analytics_events_table_for_custom_events');

        $this->artisan('spa-analytics:clean-migrations')->expectsOutputToContain('2 old migration file(s) deleted.')->assertExitCode(0);

        $this->assertFileDoesNotExist($ran);
        $this->assertFileDoesNotExist($pending);
        $this->assertSame(1, DB::table('migrations')->where('migration', 'like', '%custom_events')->count());
    }

    public function test_every_old_name_is_found_and_deleted(): void
    {
        $this->publishCurrentMigration();

        foreach (OldPatchMigrations::NAMES as $index => $name) {
            $this->harmlessFile($name, sprintf('2026_01_01_%06d', $index));
        }

        $this->assertCount(count(OldPatchMigrations::NAMES), app(OldPatchMigrations::class)->published());
        $this->assertCount(count(OldPatchMigrations::NAMES), app(OldPatchMigrations::class)->delete());
        $this->assertSame([], app(OldPatchMigrations::class)->published());
    }

    public function test_it_never_touches_files_that_only_look_similar_or_the_current_migration(): void
    {
        $current = $this->publishCurrentMigration();
        $others = [
            $this->harmlessFile('create_analytics_events_table', '2026_01_01_000003'),
            $this->harmlessFile('update_analytics_events_table_for_custom_events_backup', '2026_01_01_000005'),
            $this->harmlessFile('my_update_analytics_events_table_for_custom_events', '2026_01_01_000006'),
            $this->harmlessFile('create_posts_table', '2026_01_01_000007'),
            $current,
        ];

        $this->artisan('spa-analytics:clean-migrations')->expectsOutputToContain('No old migration files found.')->assertExitCode(0);

        foreach ($others as $file) {
            $this->assertFileExists($file);
        }
    }

    public function test_a_file_in_another_packages_vendor_folder_is_never_deleted(): void
    {
        $this->publishCurrentMigration();
        $directory = base_path('vendor/other-package/database/migrations');
        File::ensureDirectoryExists($directory);
        file_put_contents($directory.'/2026_01_01_000000_update_analytics_events_table_for_custom_events.php', '<?php return new class extends \Illuminate\Database\Migrations\Migration {};');
        app('migrator')->path($directory);

        $this->assertSame([], app(OldPatchMigrations::class)->published());
        $this->assertSame([], app(OldPatchMigrations::class)->delete());
        $this->assertFileExists($directory.'/2026_01_01_000000_update_analytics_events_table_for_custom_events.php');
    }

    public function test_a_folder_reached_by_two_spellings_is_cleaned_once_without_an_error(): void
    {
        $this->publishCurrentMigration();
        $this->harmlessFile('update_analytics_events_table_for_custom_events');
        app('migrator')->path(database_path('migrations/../migrations'));

        $this->assertCount(1, app(OldPatchMigrations::class)->published());
        $this->assertSame(['2026_01_01_000000_update_analytics_events_table_for_custom_events'], app(OldPatchMigrations::class)->delete());
    }

    public function test_a_migration_path_that_is_a_single_file_does_not_stop_the_cleanup(): void
    {
        $this->publishCurrentMigration();
        $old = $this->harmlessFile('update_analytics_events_table_for_custom_events');
        $base = (string) tempnam(sys_get_temp_dir(), 'spa-single-');
        $single = $base.'.php';
        file_put_contents($single, '<?php return new class extends \Illuminate\Database\Migrations\Migration {};');
        app('migrator')->path($single);

        try {
            $this->assertSame(['2026_01_01_000000_update_analytics_events_table_for_custom_events'], app(OldPatchMigrations::class)->delete());
            $this->assertFileDoesNotExist($old);
        } finally {
            @unlink($single);
            @unlink($base);
        }
    }

    public function test_a_symlinked_old_file_loses_the_link_and_never_its_target(): void
    {
        $this->publishCurrentMigration();
        $target = tempnam(sys_get_temp_dir(), 'spa-target-');
        $link = database_path('migrations/2026_01_01_000000_update_analytics_events_table_for_custom_events.php');
        symlink((string) $target, $link);

        try {
            $this->assertCount(1, app(OldPatchMigrations::class)->delete());

            $this->assertFalse(is_link($link));
            $this->assertFileExists((string) $target);
        } finally {
            @unlink($link);
            @unlink((string) $target);
        }
    }

    public function test_a_migration_folder_with_glob_characters_in_its_name_is_cleaned(): void
    {
        $this->publishCurrentMigration();
        $directory = sys_get_temp_dir().'/spa[1]-migrations-'.Str::random(6);
        File::ensureDirectoryExists($directory);
        $file = $directory.'/2026_01_01_000000_update_analytics_events_table_for_custom_events.php';
        file_put_contents($file, '<?php return new class extends \Illuminate\Database\Migrations\Migration {};');
        app('migrator')->path($directory);

        try {
            $this->assertCount(1, app(OldPatchMigrations::class)->published());
            $this->assertCount(1, app(OldPatchMigrations::class)->delete());
            $this->assertFileDoesNotExist($file);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_the_about_command_lists_old_files(): void
    {
        $about = function (): string {
            Artisan::call('about', ['--json' => true]);

            /** @var array<string, array<string, string>> $data */
            $data = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            return $data[Str::snake('SPA Analytics')]['old_migration_files'];
        };

        $this->assertSame('none', $about());

        $this->harmlessFile('update_analytics_events_table_for_custom_events');

        $this->assertSame('1 (removed at the next migrate)', $about());
    }
}
