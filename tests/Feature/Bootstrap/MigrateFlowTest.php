<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap\Concerns\WritesMigrationFiles;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateFlowTest extends TestCase
{
    use WithConsoleEvents;
    use WritesMigrationFiles;

    private const TABLES = ['analytics_events', 'analytics_sessions', 'analytics_rollups', 'analytics_visitor_fingerprints', 'analytics_visitor_links'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->removePublishedMigrations();
    }

    protected function tearDown(): void
    {
        $this->removePublishedMigrations();

        parent::tearDown();
    }

    /**
     * This test runs the real publish and migrate commands, so the package tables are not created up front.
     */
    protected function defineDatabaseMigrations(): void
    {
        foreach ([...self::TABLES, 'migrations'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function removePublishedMigrations(): void
    {
        foreach (glob(database_path('migrations/*_analytics_*.php')) ?: [] as $migration) {
            @unlink($migration);
        }
    }

    /**
     * @return array<int, string>
     */
    private function published(): array
    {
        $files = array_map('basename', glob(database_path('migrations/*_analytics_*.php')) ?: []);
        sort($files);

        return $files;
    }

    public function test_a_fresh_install_publishes_and_migrates_to_the_final_schema(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'spa-analytics-migrations'])->assertExitCode(0);
        $this->artisan('migrate')->assertExitCode(0);

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::hasColumns('analytics_events', ['name', 'value', 'target_host', 'target_path', 'scroll_percent', 'file_extension', 'engaged_seconds']));
        $this->assertTrue(Schema::hasColumns('analytics_sessions', ['device_type', 'os', 'browser', 'browser_version', 'country', 'region', 'viewport']));
        $this->assertTrue(Schema::hasColumn('analytics_rollups', 'engaged_seconds'));
        $this->assertSame(6, DB::table('migrations')->where('migration', 'like', '%analytics%')->count());
    }

    public function test_the_update_migration_is_published_last_so_it_runs_after_every_create(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'spa-analytics-migrations'])->assertExitCode(0);

        $files = $this->published();

        $this->assertCount(6, $files);
        $this->assertStringEndsWith('update_analytics_tables_rev_3.php', (string) end($files));
    }

    public function test_publishing_again_adds_nothing_and_migrating_again_changes_nothing(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'spa-analytics-migrations'])->assertExitCode(0);
        $this->artisan('migrate')->assertExitCode(0);
        $files = $this->published();

        $this->artisan('vendor:publish', ['--tag' => 'spa-analytics-migrations'])->assertExitCode(0);
        $this->artisan('migrate')->assertExitCode(0);

        $this->assertSame($files, $this->published());
        $this->assertSame(6, DB::table('migrations')->where('migration', 'like', '%analytics%')->count());
    }

    public function test_a_0_7_0_app_publishes_again_and_ends_with_the_old_update_files_gone_and_nothing_else_changed(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'spa-analytics-migrations'])->assertExitCode(0);

        foreach (glob(database_path('migrations/*_update_analytics_tables_rev_3.php')) ?: [] as $current) {
            unlink($current);
        }

        $oldWithColumn = $this->crashingFile();
        $oldHarmless = $this->harmlessFile('update_analytics_sessions_table_for_viewport', '2026_01_01_000001');
        $this->markRan('2026_01_01_000000_update_analytics_events_table_for_custom_events');
        $this->markRan('2026_01_01_000001_update_analytics_sessions_table_for_viewport');
        $creates = $this->hashes('*_create_analytics_*.php');

        $this->artisan('vendor:publish', ['--tag' => 'spa-analytics-migrations'])->assertExitCode(0);

        $this->assertSame($creates, $this->hashes('*_create_analytics_*.php'));
        $this->assertCount(1, glob(database_path('migrations/*_update_analytics_tables_rev_3.php')) ?: []);
        $this->assertFileDoesNotExist($oldWithColumn);
        $this->assertFileDoesNotExist($oldHarmless);
        $this->assertSame(2, DB::table('migrations')->where('migration', 'like', '2026_01_01_%')->count());

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertTrue(DB::table('migrations')->where('migration', 'like', '%update_analytics_tables_rev_3')->exists());
    }

    /**
     * @return array<string, string>
     */
    private function hashes(string $pattern): array
    {
        $hashes = [];

        foreach (glob(database_path('migrations/'.$pattern)) ?: [] as $file) {
            $hashes[basename($file)] = (string) md5_file($file);
        }

        return $hashes;
    }

    public function test_rolling_back_removes_the_tables_and_migrating_again_restores_them(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'spa-analytics-migrations'])->assertExitCode(0);
        $this->artisan('migrate')->assertExitCode(0);

        $this->artisan('migrate:rollback')->assertExitCode(0);

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        $this->artisan('migrate')->assertExitCode(0);

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }
}
