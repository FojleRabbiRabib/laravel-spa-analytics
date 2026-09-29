<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Data;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\FingerprintSignals;
use PHPUnit\Framework\TestCase;

class FingerprintSignalsTest extends TestCase
{
    /**
     * @return array<string, int|string>
     */
    private function sample(): array
    {
        return [
            'screenWidth' => 1920, 'screenHeight' => 1080, 'colorDepth' => 24,
            'timezone' => 'Asia/Dhaka', 'hardwareConcurrency' => 8, 'deviceMemory' => 800,
            'platform' => 'Linux x86_64', 'languages' => 'en-US,en', 'maxTouchPoints' => 0,
            'webglVendor' => 'Google Inc.', 'webglRenderer' => 'ANGLE (Mesa)',
            'canvasHash' => str_repeat('a', 64), 'audioHash' => '',
        ];
    }

    public function test_from_array_maps_fields_and_turns_empty_hashes_into_null(): void
    {
        $signals = FingerprintSignals::fromArray($this->sample());

        $this->assertSame(1920, $signals->screenWidth);
        $this->assertSame('Asia/Dhaka', $signals->timezone);
        $this->assertSame(str_repeat('a', 64), $signals->canvasHash);
        $this->assertNull($signals->audioHash);
    }
}
