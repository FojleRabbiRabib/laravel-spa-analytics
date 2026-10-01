<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\DeviceDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLocator;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\DeviceInfo;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AudienceCaptureTest extends TestCase
{
    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.tracking.write_mode', 'sync');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/page', fn () => '<html><body>hello</body></html>');
    }

    public function test_the_session_records_device_os_and_browser_from_the_user_agent(): void
    {
        $this->withHeaders(['User-Agent' => self::IPHONE])->get('/page')->assertOk();

        $session = AnalyticsSession::query()->sole();

        $this->assertSame(DeviceType::Mobile, $session->device_type);
        $this->assertSame('iOS', $session->os);
        $this->assertSame('Safari', $session->browser);
        $this->assertSame('17', $session->browser_version);
    }

    public function test_the_country_comes_from_the_configured_header(): void
    {
        config()->set('spa-analytics.audience.country_header', 'CF-IPCountry');

        $this->withHeaders(['User-Agent' => self::IPHONE, 'CF-IPCountry' => 'bd'])->get('/page')->assertOk();

        $this->assertSame('BD', AnalyticsSession::query()->sole()->country);
    }

    public function test_the_country_stays_empty_when_no_header_is_configured(): void
    {
        $this->withHeaders(['User-Agent' => self::IPHONE, 'CF-IPCountry' => 'BD'])->get('/page')->assertOk();

        $this->assertNull(AnalyticsSession::query()->sole()->country);
    }

    public function test_a_bot_is_still_flagged_and_gets_no_device_details(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->get('/page')->assertOk();

        $session = AnalyticsSession::query()->sole();

        $this->assertTrue($session->is_bot);
        $this->assertSame(DeviceType::Unknown, $session->device_type);
    }

    public function test_a_failing_geo_locator_only_loses_the_country(): void
    {
        Log::spy();
        $this->app->bind(GeoLocator::class, fn () => new class implements GeoLocator
        {
            public function country(Request $request): ?string
            {
                throw new \RuntimeException('no record for 203.0.113.9');
            }
        });

        $this->withHeaders(['User-Agent' => self::IPHONE])->get('/page')->assertOk();

        $this->assertSame(1, AnalyticsEvent::query()->count());
        $session = AnalyticsSession::query()->sole();
        $this->assertNull($session->country);
        $this->assertSame('iOS', $session->os);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => array_keys($context) === ['exception', 'code']
                && ! str_contains(json_encode($context), '203.0.113.9'),
        );
    }

    public function test_a_failing_device_detector_only_loses_the_device_values(): void
    {
        $this->app->bind(DeviceDetector::class, fn () => new class implements DeviceDetector
        {
            public function detect(?string $userAgent): DeviceInfo
            {
                throw new \RuntimeException('parser crashed');
            }
        });
        config()->set('spa-analytics.audience.country_header', 'CF-IPCountry');

        $this->withHeaders(['User-Agent' => self::IPHONE, 'CF-IPCountry' => 'BD'])->get('/page')->assertOk();

        $session = AnalyticsSession::query()->sole();
        $this->assertSame(DeviceType::Unknown, $session->device_type);
        $this->assertNull($session->os);
        $this->assertSame('BD', $session->country);
        $this->assertSame(1, AnalyticsEvent::query()->count());
    }

    public function test_values_from_a_custom_detector_are_cut_to_the_column_widths(): void
    {
        $this->app->bind(DeviceDetector::class, fn () => new class implements DeviceDetector
        {
            public function detect(?string $userAgent): DeviceInfo
            {
                return new DeviceInfo(DeviceType::Desktop, str_repeat('o', 50), str_repeat('b', 50), str_repeat('1', 30));
            }
        });

        $this->get('/page')->assertOk();

        $session = AnalyticsSession::query()->sole();
        $this->assertSame(32, strlen($session->os));
        $this->assertSame(32, strlen($session->browser));
        $this->assertSame(16, strlen($session->browser_version));
    }
}
