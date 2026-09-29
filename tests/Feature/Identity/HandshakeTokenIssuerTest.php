<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\HandshakeTokenIssuer;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class HandshakeTokenIssuerTest extends TestCase
{
    public function test_issued_nonce_verifies_for_the_same_visitor(): void
    {
        $issuer = new HandshakeTokenIssuer;
        $handshake = $issuer->issue('visitor-1');

        $id = $issuer->verify($handshake->nonce, 'visitor-1');

        $this->assertNotNull($id);
        $this->assertSame(16, strlen($id));
        $this->assertSame(base64_encode($issuer->encryptionKey($id)), $handshake->key);
        $this->assertGreaterThan(now()->timestamp, $handshake->expiresAt);
    }

    public function test_nonce_is_bound_to_the_visitor(): void
    {
        $issuer = new HandshakeTokenIssuer;

        $this->assertNull($issuer->verify($issuer->issue('visitor-1')->nonce, 'visitor-2'));
    }

    public function test_expired_nonce_is_rejected(): void
    {
        $issuer = new HandshakeTokenIssuer;
        $handshake = $issuer->issue('visitor-1');

        $this->travel(61)->seconds();

        $this->assertNull($issuer->verify($handshake->nonce, 'visitor-1'));
    }

    public function test_tampered_or_malformed_nonces_are_rejected(): void
    {
        $issuer = new HandshakeTokenIssuer;
        $nonce = $issuer->issue('visitor-1')->nonce;

        $this->assertNull($issuer->verify(substr($nonce, 0, -2).'AA', 'visitor-1'));
        $this->assertNull($issuer->verify('garbage', 'visitor-1'));
        $this->assertNull($issuer->verify('a.b', 'visitor-1'));
    }

    public function test_keys_differ_per_nonce_id(): void
    {
        $issuer = new HandshakeTokenIssuer;

        $this->assertNotSame($issuer->encryptionKey(str_repeat('a', 16)), $issuer->encryptionKey(str_repeat('b', 16)));
        $this->assertSame(32, strlen($issuer->encryptionKey(str_repeat('a', 16))));
    }
}
