<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\FingerprintSignals;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\FingerprintHasher;
use PHPUnit\Framework\TestCase;

class FingerprintHasherTest extends TestCase
{
    private function signals(
        ?string $canvas = null,
        ?string $audio = null,
        string $timezone = 'Asia/Dhaka',
        int $width = 1920,
        int $height = 1080,
        string $renderer = 'ANGLE (Mesa)',
    ): FingerprintSignals {
        return new FingerprintSignals(
            $width, $height, 24, $timezone, 8, 800, 'Linux x86_64', 'en-US,en', 0,
            'Google Inc.', $renderer, $canvas, $audio,
        );
    }

    public function test_output_is_deterministic(): void
    {
        $hasher = new FingerprintHasher;

        $this->assertEquals(
            $hasher->hash($this->signals('c', 'a'), 'ja4'),
            $hasher->hash($this->signals('c', 'a'), 'ja4'),
        );
    }

    public function test_stable_hash_ignores_volatile_signals(): void
    {
        $hasher = new FingerprintHasher;

        $one = $hasher->hash($this->signals('canvas-1', 'audio-1'), 'ja4-1');
        $two = $hasher->hash($this->signals('canvas-2', 'audio-2', renderer: 'Other GPU'), 'ja4-2');

        $this->assertSame($one->stableHash, $two->stableHash);
        $this->assertNotSame($one->canvasHash, $two->canvasHash);
        $this->assertNotSame($one->audioHash, $two->audioHash);
        $this->assertNotSame($one->webglHash, $two->webglHash);
        $this->assertNotSame($one->tlsHash, $two->tlsHash);
    }

    public function test_stable_hash_changes_with_device_signals(): void
    {
        $hasher = new FingerprintHasher;

        $this->assertNotSame(
            $hasher->hash($this->signals(timezone: 'Asia/Dhaka'))->stableHash,
            $hasher->hash($this->signals(timezone: 'Europe/Paris'))->stableHash,
        );
    }

    public function test_rotated_screen_keeps_the_stable_hash(): void
    {
        $hasher = new FingerprintHasher;

        $this->assertSame(
            $hasher->hash($this->signals(width: 1080, height: 1920))->stableHash,
            $hasher->hash($this->signals(width: 1920, height: 1080))->stableHash,
        );
    }

    public function test_missing_volatile_signals_are_null(): void
    {
        $fingerprint = (new FingerprintHasher)->hash(
            new FingerprintSignals(1, 2, 24, 'UTC', 4, 800, 'Linux', 'en', 0, '', '', null, null),
            null,
        );

        $this->assertNull($fingerprint->canvasHash);
        $this->assertNull($fingerprint->audioHash);
        $this->assertNull($fingerprint->webglHash);
        $this->assertNull($fingerprint->tlsHash);
        $this->assertSame(64, strlen($fingerprint->stableHash));
    }

    public function test_tiers_are_namespaced_so_equal_values_do_not_collide(): void
    {
        $fingerprint = (new FingerprintHasher)->hash($this->signals('same', 'same'), 'same');

        $this->assertNotSame($fingerprint->canvasHash, $fingerprint->audioHash);
        $this->assertNotSame($fingerprint->audioHash, $fingerprint->tlsHash);
    }
}
