<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap\Concerns\WritesMigrationFiles;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\DB;

class CleanOldMigrationsListenerTest extends TestCase
{
    use WithConsoleEvents;
    use WritesMigrationFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->removeMigrationFiles();
    }

    protected function tearDown(): void
    {
        $this->removeMigrationFiles();
        @unlink(config_path('spa-analytics.php'));

        parent::tearDown();
    }

    public function test_migrate_deletes_the_old_files_before_they_can_run_and_fail(): void
    {
        $this->publishCurrentMigration();
        $old = $this->crashingFile();

        $this->artisan('migrate')->expectsOutputToContain('deleted 1 old migration file(s)')->assertExitCode(0);

        $this->assertFileDoesNotExist($old);
        $this->assertFalse(DB::table('migrations')->where('migration', 'like', '%custom_events')->exists());
    }

    public function test_migrate_fresh_deletes_them_too(): void
    {
        $this->publishCurrentMigration();
        $old = $this->crashingFile();

        $this->artisan('migrate:fresh')->assertExitCode(0);

        $this->assertFileDoesNotExist($old);
    }

    public function test_migrate_keeps_the_old_files_while_the_current_update_migration_is_not_published(): void
    {
        $old = $this->harmlessFile('update_analytics_events_table_for_custom_events');

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertFileExists($old);
    }

    public function test_a_pretend_run_deletes_nothing(): void
    {
        $this->publishCurrentMigration();
        $old = $this->harmlessFile('update_analytics_events_table_for_custom_events');

        $this->artisan('migrate', ['--pretend' => true]);

        $this->assertFileExists($old);
    }

    public function test_migrate_status_deletes_nothing(): void
    {
        $this->publishCurrentMigration();
        $old = $this->harmlessFile('update_analytics_events_table_for_custom_events');

        $this->artisan('migrate:status');

        $this->assertFileExists($old);
    }

    public function test_publishing_the_package_tags_deletes_the_old_files(): void
    {
        $this->publishCurrentMigration();
        $old = $this->harmlessFile('update_analytics_events_table_for_custom_events');

        $this->artisan('vendor:publish', ['--tag' => 'spa-analytics-config'])->assertExitCode(0);

        $this->assertFileDoesNotExist($old);
    }

    public function test_a_file_restored_from_git_is_deleted_again_before_the_next_migrate(): void
    {
        $this->publishCurrentMigration();
        $this->crashingFile();
        $this->artisan('migrate')->assertExitCode(0);

        $restored = $this->crashingFile();

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertFileDoesNotExist($restored);
    }
}
