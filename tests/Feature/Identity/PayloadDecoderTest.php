<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Exceptions\InvalidPayload;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\HandshakeTokenIssuer;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\PayloadDecoder;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support\PayloadEncoder;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class PayloadDecoderTest extends TestCase
{
    private const GOLDEN = '0100010002001800035554430004032000054c696e75780002656e000000015600015200000000';

    private function decoder(): PayloadDecoder
    {
        return new PayloadDecoder(new HandshakeTokenIssuer);
    }

    /**
     * @param  array<string, int|string>  $overrides
     */
    private function body(string $visitor = 'visitor-1', array $overrides = []): string
    {
        $handshake = (new HandshakeTokenIssuer)->issue($visitor);

        return PayloadEncoder::body(
            PayloadEncoder::plaintext(PayloadEncoder::sample($overrides)),
            $handshake->nonce,
            $handshake->key,
        );
    }

    public function test_round_trip_returns_the_signals(): void
    {
        $signals = $this->decoder()->decode($this->body(), 'visitor-1');

        $this->assertSame(1920, $signals->screenWidth);
        $this->assertSame('Asia/Dhaka', $signals->timezone);
        $this->assertSame(str_repeat('a', 64), $signals->canvasHash);
    }

    public function test_golden_plaintext_decodes(): void
    {
        $signals = $this->decoder()->decodePlaintext((string) hex2bin(self::GOLDEN));

        $this->assertSame(1, $signals->screenWidth);
        $this->assertSame('UTC', $signals->timezone);
        $this->assertSame(800, $signals->deviceMemory);
        $this->assertSame('Linux', $signals->platform);
        $this->assertNull($signals->canvasHash);
        $this->assertNull($signals->audioHash);
    }

    public function test_golden_matches_the_test_encoder(): void
    {
        $plaintext = PayloadEncoder::plaintext(PayloadEncoder::sample([
            'screenWidth' => 1, 'screenHeight' => 2, 'colorDepth' => 24, 'timezone' => 'UTC',
            'hardwareConcurrency' => 4, 'deviceMemory' => 800, 'platform' => 'Linux', 'languages' => 'en',
            'maxTouchPoints' => 0, 'webglVendor' => 'V', 'webglRenderer' => 'R', 'canvasHash' => '', 'audioHash' => '',
        ]));

        $this->assertSame(self::GOLDEN, bin2hex($plaintext));
    }

    public function test_replay_is_rejected(): void
    {
        $body = $this->body();
        $this->decoder()->decode($body, 'visitor-1');

        $this->expectException(InvalidPayload::class);
        $this->decoder()->decode($body, 'visitor-1');
    }

    public function test_expired_nonce_is_rejected(): void
    {
        $body = $this->body();
        $this->travel(61)->seconds();

        $this->expectException(InvalidPayload::class);
        $this->decoder()->decode($body, 'visitor-1');
    }

    public function test_nonce_for_another_visitor_is_rejected(): void
    {
        $this->expectException(InvalidPayload::class);
        $this->decoder()->decode($this->body('visitor-1'), 'visitor-2');
    }

    public function test_tampered_ciphertext_is_rejected(): void
    {
        $body = $this->body();
        $body[strlen($body) - 20] = $body[strlen($body) - 20] ^ "\x01";

        $this->expectException(InvalidPayload::class);
        $this->decoder()->decode($body, 'visitor-1');
    }

    public function test_unsupported_version_is_rejected(): void
    {
        $body = $this->body();
        $body[0] = "\x09";

        $this->expectException(InvalidPayload::class);
        $this->decoder()->decode($body, 'visitor-1');
    }

    public function test_oversized_field_is_rejected(): void
    {
        $this->expectException(InvalidPayload::class);
        $this->decoder()->decode($this->body('visitor-1', ['timezone' => str_repeat('x', 65)]), 'visitor-1');
    }

    public function test_bad_hash_format_is_rejected(): void
    {
        $this->expectException(InvalidPayload::class);
        $this->decoder()->decode($this->body('visitor-1', ['canvasHash' => 'not-hex']), 'visitor-1');
    }

    public function test_oversized_body_is_rejected_before_any_work(): void
    {
        $this->expectException(InvalidPayload::class);
        $this->decoder()->decode(str_repeat('x', 2049), 'visitor-1');
    }

    public function test_trailing_bytes_and_truncation_are_rejected(): void
    {
        $plaintext = (string) hex2bin(self::GOLDEN);

        try {
            $this->decoder()->decodePlaintext($plaintext.'x');
            $this->fail('trailing bytes accepted');
        } catch (InvalidPayload) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidPayload::class);
        $this->decoder()->decodePlaintext(substr($plaintext, 0, 10));
    }

    public function test_garbage_body_is_rejected(): void
    {
        $this->expectException(InvalidPayload::class);
        $this->decoder()->decode('garbage', 'visitor-1');
    }
}
