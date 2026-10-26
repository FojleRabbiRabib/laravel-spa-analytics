<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseConnectionMigrateTest extends TestCase
{
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

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.analytics', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('spa-analytics.database.connection', 'analytics');
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach ([...self::TABLES, 'migrations'] as $table) {
            Schema::connection('testing')->dropIfExists($table);
        }
    }

    private function removePublishedMigrations(): void
    {
        foreach (glob(database_path('migrations/*_analytics_*.php')) ?: [] as $migration) {
            @unlink($migration);
        }
    }

    private function migrate(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'spa-analytics-migrations'])->assertExitCode(0);
        $this->artisan('migrate')->assertExitCode(0);
    }

    public function test_migrate_creates_the_tables_on_the_configured_connection_and_records_the_run_on_the_default_one(): void
    {
        $this->migrate();

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::connection('analytics')->hasTable($table), $table);
            $this->assertFalse(Schema::connection('testing')->hasTable($table), $table);
        }

        $this->assertTrue(Schema::connection('analytics')->hasColumns('analytics_events', ['name', 'engaged_seconds']));
        $this->assertSame(6, DB::connection('testing')->table('migrations')->where('migration', 'like', '%analytics%')->count());
        $this->assertFalse(Schema::connection('analytics')->hasTable('migrations'));
    }

    public function test_the_update_migration_runs_on_the_configured_connection_too(): void
    {
        $this->migrate();
        Schema::connection('analytics')->table('analytics_events', fn ($table) => $table->dropColumn('engaged_seconds'));
        DB::connection('testing')->table('migrations')->where('migration', 'like', '%update_analytics_tables_rev_3')->delete();

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertTrue(Schema::connection('analytics')->hasColumn('analytics_events', 'engaged_seconds'));
        $this->assertFalse(Schema::connection('testing')->hasTable('analytics_events'));
    }

    public function test_rolling_back_drops_the_tables_from_the_configured_connection(): void
    {
        $this->migrate();

        $this->artisan('migrate:rollback')->assertExitCode(0);

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::connection('analytics')->hasTable($table), $table);
        }
    }
}
