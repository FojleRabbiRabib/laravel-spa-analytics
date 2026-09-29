<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\Fingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\FingerprintMatcher;
use PHPUnit\Framework\TestCase;

class FingerprintMatcherTest extends TestCase
{
    public function test_same_stable_and_one_volatile_match_passes(): void
    {
        $a = new Fingerprint('s', 'canvas-old', 'audio', 'webgl-old', 'tls-old');
        $b = new Fingerprint('s', 'canvas-new', 'audio', 'webgl-new', 'tls-new');

        $this->assertTrue((new FingerprintMatcher)->matches($a, $b));
    }

    public function test_same_stable_but_no_volatile_match_fails(): void
    {
        $a = new Fingerprint('s', 'c1', 'a1', 'w1', 't1');
        $b = new Fingerprint('s', 'c2', 'a2', 'w2', 't2');

        $this->assertFalse((new FingerprintMatcher)->matches($a, $b));
    }

    public function test_different_stable_fails_even_when_volatile_matches(): void
    {
        $a = new Fingerprint('s1', 'c', 'a', 'w', 't');
        $b = new Fingerprint('s2', 'c', 'a', 'w', 't');

        $this->assertFalse((new FingerprintMatcher)->matches($a, $b));
    }

    public function test_null_tiers_never_match(): void
    {
        $a = new Fingerprint('s', null, null, null, null);
        $b = new Fingerprint('s', null, null, null, null);

        $this->assertFalse((new FingerprintMatcher)->matches($a, $b));
    }

    public function test_webgl_only_match_passes(): void
    {
        $a = new Fingerprint('s', 'c1', null, 'w', null);
        $b = new Fingerprint('s', 'c2', null, 'w', null);

        $this->assertTrue((new FingerprintMatcher)->matches($a, $b));
    }

    public function test_tls_only_match_passes(): void
    {
        $a = new Fingerprint('s', 'c1', null, null, 't');
        $b = new Fingerprint('s', 'c2', null, null, 't');

        $this->assertTrue((new FingerprintMatcher)->matches($a, $b));
    }
}
