<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Enums;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;
use PHPUnit\Framework\TestCase;

class DeviceTypeTest extends TestCase
{
    public function test_helpers(): void
    {
        $this->assertTrue(DeviceType::Mobile->is(DeviceType::Mobile));
        $this->assertFalse(DeviceType::Mobile->is(DeviceType::Tablet));
        $this->assertSame('Desktop', DeviceType::Desktop->label());
        $this->assertSame('Mobile', DeviceType::Mobile->label());
        $this->assertSame('Tablet', DeviceType::Tablet->label());
        $this->assertSame('Unknown', DeviceType::Unknown->label());
        $this->assertSame('blue', DeviceType::Desktop->color());
        $this->assertSame('green', DeviceType::Mobile->color());
        $this->assertSame('purple', DeviceType::Tablet->color());
        $this->assertSame('gray', DeviceType::Unknown->color());
        $this->assertSame(['desktop', 'mobile', 'tablet', 'unknown'], DeviceType::values());
        $this->assertSame(DeviceType::Unknown, DeviceType::default());
        $this->assertSame(
            [
                ['value' => 'desktop', 'label' => 'Desktop'],
                ['value' => 'mobile', 'label' => 'Mobile'],
                ['value' => 'tablet', 'label' => 'Tablet'],
                ['value' => 'unknown', 'label' => 'Unknown'],
            ],
            DeviceType::options(),
        );
    }
}
