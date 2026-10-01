<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class InstallCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->removePublishedFiles();
    }

    protected function tearDown(): void
    {
        $this->removePublishedFiles();

        parent::tearDown();
    }

    /**
     * The Testbench skeleton lives under vendor/ and keeps published files between runs; a leftover config
     * would outrank the package config in every later test.
     */
    private function removePublishedFiles(): void
    {
        @unlink(config_path('spa-analytics.php'));

        foreach (glob(database_path('migrations/*_analytics_*.php')) ?: [] as $migration) {
            @unlink($migration);
        }

        @unlink(public_path('vendor/laravel-spa-analytics/client.js'));
        @rmdir(public_path('vendor/laravel-spa-analytics'));
    }

    public function test_the_install_command_is_registered_but_hidden(): void
    {
        $command = Artisan::all()['spa-analytics:install'] ?? null;

        $this->assertNotNull($command);
        $this->assertTrue($command->isHidden());
    }

    public function test_the_install_command_publishes_config_migrations_and_assets(): void
    {
        $this->artisan('spa-analytics:install')
            ->expectsConfirmation('Would you like to run the migrations now?', 'no')
            ->assertExitCode(0);

        $this->assertFileExists(config_path('spa-analytics.php'));
        $this->assertFileExists(public_path('vendor/laravel-spa-analytics/client.js'));
        $this->assertCount(5, glob(database_path('migrations/*_create_analytics_*.php')));
        $this->assertCount(2, glob(database_path('migrations/*_update_analytics_*.php')));
    }
}
