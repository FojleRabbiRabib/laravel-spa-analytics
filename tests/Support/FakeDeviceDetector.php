<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\DeviceDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\DeviceInfo;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;

final class FakeDeviceDetector implements DeviceDetector
{
    public function detect(?string $userAgent): DeviceInfo
    {
        return new DeviceInfo(DeviceType::Tablet, 'FakeOS', 'FakeBrowser', '9');
    }
}
