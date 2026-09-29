<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\Handshake;

class HandshakeTokenIssuer
{
    /**
     * Issue a signed, time-limited nonce bound to the visitor, with its per-session encryption key.
     */
    public function issue(string $visitorId): Handshake
    {
        $id = random_bytes(16);
        $expiresAt = now()->addSeconds((int) config('laravel-spa-analytics.identity.nonce_ttl_seconds'))->timestamp;
        $body = $id.pack('N', $expiresAt);

        $nonce = $this->encode($body).'.'.$this->encode($this->mac($body, $visitorId));

        return new Handshake($nonce, base64_encode($this->encryptionKey($id)), $expiresAt);
    }

    /**
     * Returns the raw 16-byte nonce id when the nonce is authentic, unexpired and bound to the visitor.
     */
    public function verify(string $nonce, string $visitorId): ?string
    {
        $parts = explode('.', $nonce);

        if (count($parts) !== 2) {
            return null;
        }

        $body = $this->decode($parts[0]);
        $mac = $this->decode($parts[1]);

        if ($body === null || $mac === null || strlen($body) !== 20) {
            return null;
        }

        if (! hash_equals($this->mac($body, $visitorId), $mac)) {
            return null;
        }

        $expiresAt = unpack('N', substr($body, 16, 4));

        if ($expiresAt === false || $expiresAt[1] < now()->timestamp) {
            return null;
        }

        return substr($body, 0, 16);
    }

    /**
     * Derive the 32-byte AES key for a nonce id from the application key.
     */
    public function encryptionKey(string $nonceId): string
    {
        return hash_hmac('sha256', 'enc'.$nonceId, $this->appKey(), true);
    }

    private function mac(string $body, string $visitorId): string
    {
        return hash_hmac('sha256', 'nonce'.$body.$visitorId, $this->appKey(), true);
    }

    private function appKey(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }

    private function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function decode(string $text): ?string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
