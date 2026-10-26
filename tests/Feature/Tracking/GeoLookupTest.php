<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLocator;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLookup;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\GeoLocation;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\GeoLocatorAdapter;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\HeaderGeoLocator;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

class GeoLookupTest extends TestCase
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

    public function test_the_default_lookup_is_the_header_locator_and_the_old_contract_still_resolves(): void
    {
        $this->assertInstanceOf(HeaderGeoLocator::class, app(GeoLookup::class));
        $this->assertInstanceOf(HeaderGeoLocator::class, app(GeoLocator::class));
    }

    public function test_the_country_and_region_headers_are_stored_on_the_session(): void
    {
        config()->set('spa-analytics.audience.country_header', 'CF-IPCountry');
        config()->set('spa-analytics.audience.region_header', 'CF-Region-Code');

        $this->withHeaders(['CF-IPCountry' => 'US', 'CF-Region-Code' => 'CA'])->get('/page')->assertOk();

        $session = AnalyticsSession::query()->sole();
        $this->assertSame('US', $session->country);
        $this->assertSame('US-CA', $session->region);
    }

    public function test_a_config_without_the_region_key_gives_no_region_and_no_error(): void
    {
        $audience = config('spa-analytics.audience');
        unset($audience['region_header']);
        config()->set('spa-analytics.audience', [...$audience, 'country_header' => 'CF-IPCountry']);

        $this->withHeaders(['CF-IPCountry' => 'US', 'CF-Region-Code' => 'CA'])->get('/page')->assertOk();

        $session = AnalyticsSession::query()->sole();
        $this->assertSame('US', $session->country);
        $this->assertNull($session->region);
    }

    public function test_a_geo_locator_bound_by_the_app_is_wrapped_and_gives_a_country_with_no_region(): void
    {
        config()->set('spa-analytics.audience.region_header', 'CF-Region-Code');
        $this->app->bind(GeoLocator::class, fn () => new class implements GeoLocator
        {
            public function country(Request $request): ?string
            {
                return 'NZ';
            }
        });

        $this->assertInstanceOf(GeoLocatorAdapter::class, app(GeoLookup::class));

        $this->withHeaders(['CF-Region-Code' => 'AUK'])->get('/page')->assertOk();

        $session = AnalyticsSession::query()->sole();
        $this->assertSame('NZ', $session->country);
        $this->assertNull($session->region);
    }

    public function test_a_geo_lookup_bound_by_the_app_gives_both_values(): void
    {
        $this->app->bind(GeoLookup::class, fn () => new class implements GeoLookup
        {
            public function locate(Request $request): GeoLocation
            {
                return new GeoLocation('NZ', 'NZ-AUK');
            }
        });

        $this->get('/page')->assertOk();

        $session = AnalyticsSession::query()->sole();
        $this->assertSame('NZ', $session->country);
        $this->assertSame('NZ-AUK', $session->region);
    }

    public function test_a_region_without_a_country_from_a_custom_lookup_is_not_stored(): void
    {
        $this->app->bind(GeoLookup::class, fn () => new class implements GeoLookup
        {
            public function locate(Request $request): GeoLocation
            {
                return new GeoLocation(null, 'NZ-AUK');
            }
        });

        $this->get('/page')->assertOk();

        $this->assertNull(AnalyticsSession::query()->sole()->region);
    }

    public static function regionsFromACustomLookup(): array
    {
        return [
            'lowercase is normalised' => ['nz-auk', 'NZ-AUK'],
            'a bare subdivision code' => ['AUK', null],
            'a name' => ['Auckland', null],
            'too long' => ['NZ-AUKLAND', null],
            'another country' => ['AU-NSW', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('regionsFromACustomLookup')]
    public function test_a_region_from_a_custom_lookup_is_stored_only_as_a_full_code_of_its_country(string $region, ?string $stored): void
    {
        $this->app->bind(GeoLookup::class, fn () => new class($region) implements GeoLookup
        {
            public function __construct(private readonly string $region) {}

            public function locate(Request $request): GeoLocation
            {
                return new GeoLocation('NZ', $this->region);
            }
        });

        $this->get('/page')->assertOk();

        $session = AnalyticsSession::query()->sole();
        $this->assertSame('NZ', $session->country);
        $this->assertSame($stored, $session->region);
    }

    public function test_a_failing_lookup_only_loses_the_geo_values(): void
    {
        Log::spy();
        $this->app->bind(GeoLookup::class, fn () => new class implements GeoLookup
        {
            public function locate(Request $request): GeoLocation
            {
                throw new \RuntimeException('no record for 203.0.113.9');
            }
        });

        $this->get('/page')->assertOk();

        $this->assertSame(1, AnalyticsEvent::query()->count());
        $session = AnalyticsSession::query()->sole();
        $this->assertNull($session->country);
        $this->assertNull($session->region);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => array_keys($context) === ['exception', 'code']
                && ! str_contains(json_encode($context), '203.0.113.9'),
        );
    }
}
