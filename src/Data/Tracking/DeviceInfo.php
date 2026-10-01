<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;

final readonly class DeviceInfo
{
    public function __construct(
        public DeviceType $deviceType,
        public ?string $os,
        public ?string $browser,
        public ?string $browserVersion,
    ) {}
}
