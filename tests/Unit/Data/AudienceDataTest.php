<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Data;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\AudienceData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;
use PHPUnit\Framework\TestCase;

class AudienceDataTest extends TestCase
{
    public function test_it_defaults_to_nothing_known(): void
    {
        $audience = new AudienceData;

        $this->assertNull($audience->deviceType);
        $this->assertNull($audience->os);
        $this->assertNull($audience->browser);
        $this->assertNull($audience->browserVersion);
        $this->assertNull($audience->country);
    }

    public function test_to_array_is_column_keyed(): void
    {
        $audience = new AudienceData(DeviceType::Mobile, 'Android', 'Chrome', '121', 'BD');

        $this->assertSame([
            'device_type' => DeviceType::Mobile,
            'os' => 'Android',
            'browser' => 'Chrome',
            'browser_version' => '121',
            'country' => 'BD',
        ], $audience->toArray());
    }

    public function test_from_array_round_trips_and_accepts_plain_strings(): void
    {
        $audience = AudienceData::fromArray([
            'device_type' => 'tablet',
            'os' => 'iOS',
            'browser' => 'Safari',
            'browser_version' => '17',
            'country' => 'US',
        ]);

        $this->assertSame(DeviceType::Tablet, $audience->deviceType);
        $this->assertEquals($audience, AudienceData::fromArray($audience->toArray()));
    }

    public function test_from_array_tolerates_missing_keys_such_as_jobs_queued_before_the_upgrade(): void
    {
        $this->assertEquals(new AudienceData, AudienceData::fromArray([]));
    }
}
