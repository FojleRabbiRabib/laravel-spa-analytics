<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\PatternDeviceDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PatternDeviceDetectorTest extends TestCase
{
    /**
     * @return array<string, array{?string, DeviceType, ?string, ?string, ?string}>
     */
    public static function userAgents(): array
    {
        return [
            'chrome on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36', DeviceType::Desktop, 'Windows', 'Chrome', '121'],
            'edge on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 Edg/121.0.2277.83', DeviceType::Desktop, 'Windows', 'Edge', '121'],
            'opera on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0', DeviceType::Desktop, 'Windows', 'Opera', '106'],
            'firefox on linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0', DeviceType::Desktop, 'Linux', 'Firefox', '121'],
            'safari on macos' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15', DeviceType::Desktop, 'macOS', 'Safari', '17'],
            'chrome on chromeos' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36', DeviceType::Desktop, 'ChromeOS', 'Chrome', '121'],
            'chrome on an android phone' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Mobile Safari/537.36', DeviceType::Mobile, 'Android', 'Chrome', '121'],
            'chrome on an android tablet' => ['Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', DeviceType::Tablet, 'Android', 'Chrome', '120'],
            'firefox on android' => ['Mozilla/5.0 (Android 14; Mobile; rv:121.0) Gecko/121.0 Firefox/121.0', DeviceType::Mobile, 'Android', 'Firefox', '121'],
            'samsung internet' => ['Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36', DeviceType::Mobile, 'Android', 'Samsung Internet', '23'],
            'safari on an iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1', DeviceType::Mobile, 'iOS', 'Safari', '17'],
            'safari on an ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1', DeviceType::Tablet, 'iOS', 'Safari', '17'],
            'chrome on an iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/121.0.6167.66 Mobile/15E148 Safari/604.1', DeviceType::Mobile, 'iOS', 'Chrome', '121'],
            'firefox on an iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/121.0 Mobile/15E148 Safari/605.1.15', DeviceType::Mobile, 'iOS', 'Firefox', '121'],
            'ipados 13 and later report a mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15', DeviceType::Desktop, 'macOS', 'Safari', '17'],
            'a crawler that imitates a phone browser' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.6167.139 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', DeviceType::Mobile, 'Android', 'Chrome', '121'],
            'a crawler' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', DeviceType::Unknown, null, null, null],
            'a command line client' => ['curl/8.4.0', DeviceType::Unknown, null, null, null],
            'a missing user agent' => [null, DeviceType::Unknown, null, null, null],
            'an empty user agent' => ['', DeviceType::Unknown, null, null, null],
        ];
    }

    #[DataProvider('userAgents')]
    public function test_it_classifies_the_user_agent(?string $userAgent, DeviceType $device, ?string $os, ?string $browser, ?string $version): void
    {
        $info = (new PatternDeviceDetector)->detect($userAgent);

        $this->assertSame($device, $info->deviceType);
        $this->assertSame($os, $info->os);
        $this->assertSame($browser, $info->browser);
        $this->assertSame($version, $info->browserVersion);
    }
}
