<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLookup;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\HeaderGeoLocator;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\MaxMindGeoLookup;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;

class MaxMindGeoLookupFeatureTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.tracking.write_mode', 'sync');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/page', fn () => '<html><body>hello</body></html>');
    }

    private function useDatabase(mixed $record): void
    {
        config()->set('spa-analytics.audience.maxmind_database', 'GeoLite2-City.mmdb');

        $reader = $this->createMock(Reader::class);
        $reader->method('get')->willReturn($record);
        $this->app->instance(MaxMindGeoLookup::class, new MaxMindGeoLookup('GeoLite2-City.mmdb', $reader));
    }

    public function test_without_a_configured_database_the_header_lookup_stays_the_default(): void
    {
        $this->assertInstanceOf(HeaderGeoLocator::class, app(GeoLookup::class));
    }

    public function test_a_configured_database_stores_the_country_and_region_of_the_request_address(): void
    {
        $this->useDatabase(['country' => ['iso_code' => 'US'], 'subdivisions' => [['iso_code' => 'CA']]]);

        $this->assertInstanceOf(MaxMindGeoLookup::class, app(GeoLookup::class));

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/page')->assertOk();

        $session = AnalyticsSession::query()->sole();
        $this->assertSame('US', $session->country);
        $this->assertSame('US-CA', $session->region);
    }

    public function test_the_database_is_the_only_source_and_the_headers_are_ignored(): void
    {
        config()->set('spa-analytics.audience.country_header', 'CF-IPCountry');
        config()->set('spa-analytics.audience.region_header', 'CF-Region-Code');
        $this->useDatabase(['country' => ['iso_code' => 'DE']]);

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->withHeaders(['CF-IPCountry' => 'US', 'CF-Region-Code' => 'CA'])
            ->get('/page')
            ->assertOk();

        $session = AnalyticsSession::query()->sole();
        $this->assertSame('DE', $session->country);
        $this->assertNull($session->region);
    }

    public function test_a_local_address_stores_no_country_and_no_error_is_logged(): void
    {
        Log::spy();
        $this->useDatabase(['country' => ['iso_code' => 'US']]);

        $this->get('/page')->assertOk();

        $this->assertNull(AnalyticsSession::query()->sole()->country);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_missing_database_file_only_loses_the_geo_values(): void
    {
        Log::spy();
        config()->set('spa-analytics.audience.maxmind_database', '/nonexistent/GeoLite2-City.mmdb');

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/page')->assertOk();

        $this->assertSame(1, AnalyticsEvent::query()->count());
        $session = AnalyticsSession::query()->sole();
        $this->assertNull($session->country);
        $this->assertNull($session->region);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => array_keys($context) === ['exception', 'code']
                && ! str_contains(json_encode($context), '8.8.8.8'),
        );
    }
}
