<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support;

final class PayloadEncoder
{
    /**
     * @param  array<string, int|string>  $overrides
     * @return array<string, int|string>
     */
    public static function sample(array $overrides = []): array
    {
        return array_merge([
            'screenWidth' => 1920, 'screenHeight' => 1080, 'colorDepth' => 24,
            'timezone' => 'Asia/Dhaka', 'hardwareConcurrency' => 8, 'deviceMemory' => 800,
            'platform' => 'Linux x86_64', 'languages' => 'en-US,en', 'maxTouchPoints' => 0,
            'webglVendor' => 'Google Inc.', 'webglRenderer' => 'ANGLE (Mesa)',
            'canvasHash' => str_repeat('a', 64), 'audioHash' => str_repeat('b', 64),
        ], $overrides);
    }

    /**
     * @param  array<string, int|string>  $s
     */
    public static function plaintext(array $s): string
    {
        $u16 = fn (int|string $n): string => pack('n', (int) $n);
        $str = fn (int|string $v): string => pack('n', strlen((string) $v)).$v;

        return chr(1)
            .$u16($s['screenWidth']).$u16($s['screenHeight']).$u16($s['colorDepth'])
            .$str($s['timezone']).$u16($s['hardwareConcurrency']).$u16($s['deviceMemory'])
            .$str($s['platform']).$str($s['languages']).$u16($s['maxTouchPoints'])
            .$str($s['webglVendor']).$str($s['webglRenderer'])
            .$str($s['canvasHash']).$str($s['audioHash']);
    }

    public static function body(string $plaintext, string $nonce, string $keyBase64): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            (string) gzencode($plaintext),
            'aes-256-gcm',
            (string) base64_decode($keyBase64),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $nonce,
        );

        return chr(1).pack('n', strlen($nonce)).$nonce.$iv.$cipher.$tag;
    }
}
