<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\LaravelSpaAnalyticsServiceProvider;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\ServiceProvider;

class ServiceProviderTest extends TestCase
{
    public function test_service_provider_is_registered(): void
    {
        $this->assertTrue(
            $this->app->providerIsLoaded(LaravelSpaAnalyticsServiceProvider::class)
        );
    }

    public function test_migrations_are_publishable(): void
    {
        $paths = ServiceProvider::pathsToPublish(LaravelSpaAnalyticsServiceProvider::class, 'spa-analytics-migrations');
        $sources = array_map('basename', array_keys($paths));

        $this->assertContains('create_analytics_events_table.php.stub', $sources);
        $this->assertContains('create_analytics_visitor_fingerprints_table.php.stub', $sources);
    }

    public function test_config_file_is_merged(): void
    {
        $this->assertTrue(config()->has('laravel-spa-analytics'));
        $this->assertTrue(config('laravel-spa-analytics.enabled'));
    }
}
