<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Data;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\Fingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;
use PHPUnit\Framework\TestCase;

class VisitorIdentityTest extends TestCase
{
    public function test_from_array_builds_identity_without_fingerprint(): void
    {
        $identity = VisitorIdentity::fromArray(['id' => 'abc', 'source' => 'cookie']);

        $this->assertSame('abc', $identity->id);
        $this->assertSame(IdentitySource::Cookie, $identity->source);
        $this->assertNull($identity->fingerprint);
    }

    public function test_with_fingerprint_returns_a_new_instance(): void
    {
        $identity = new VisitorIdentity('abc', IdentitySource::Generated);
        $fingerprint = new Fingerprint('stable', 'canvas', null, null, null);

        $updated = $identity->withFingerprint($fingerprint);

        $this->assertNull($identity->fingerprint);
        $this->assertSame($fingerprint, $updated->fingerprint);
        $this->assertSame('abc', $updated->id);
    }

    public function test_fingerprint_from_array(): void
    {
        $fingerprint = Fingerprint::fromArray(['stableHash' => 's', 'canvasHash' => 'c']);

        $this->assertSame('s', $fingerprint->stableHash);
        $this->assertSame('c', $fingerprint->canvasHash);
        $this->assertNull($fingerprint->audioHash);
        $this->assertNull($fingerprint->webglHash);
        $this->assertNull($fingerprint->tlsHash);
    }
}
