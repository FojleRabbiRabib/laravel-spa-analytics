<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\BotDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\LaravelSpaAnalyticsServiceProvider;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\DatabaseEventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\PatternBotDetector;
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
        $this->assertContains('create_analytics_sessions_table.php.stub', $sources);
        $this->assertContains('create_analytics_visitor_links_table.php.stub', $sources);
    }

    public function test_contracts_resolve_to_the_package_defaults(): void
    {
        $this->assertInstanceOf(PatternBotDetector::class, app(BotDetector::class));
        $this->assertInstanceOf(DatabaseEventStore::class, app(EventStore::class));
    }

    public function test_the_bot_detector_uses_the_configured_patterns(): void
    {
        config()->set('spa-analytics.tracking.bot_patterns', ['zzbot']);

        $this->assertTrue(app(BotDetector::class)->isBot('Mozilla zzbot/1.0'));
        $this->assertFalse(app(BotDetector::class)->isBot('Mozilla Googlebot/2.1'));
    }

    public function test_config_file_is_merged(): void
    {
        $this->assertTrue(config()->has('spa-analytics'));
        $this->assertTrue(config('spa-analytics.enabled'));
    }
}
