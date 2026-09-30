<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\FingerprintSignals;
use FojleRabbiRabib\LaravelSpaAnalytics\Exceptions\InvalidPayload;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\PayloadDecoder;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

/**
 * Decodes the frozen envelope that the TypeScript test (envelope-fixture.test.ts) reproduces byte for byte, so a
 * format change on either side fails one of the two suites.
 */
class PayloadFixtureTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/identify-envelope.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function decoder(): PayloadDecoder
    {
        return app(PayloadDecoder::class);
    }

    private function at(string $at): void
    {
        $this->travelTo(CarbonImmutable::parse($at));
    }

    public function test_the_frozen_envelope_decodes_into_the_expected_signals(): void
    {
        $fixture = $this->fixture();
        $this->at($fixture['frozenAt']);

        $signals = $this->decoder()->decode(base64_decode($fixture['bodyBase64'], true), $fixture['visitorId']);

        $this->assertEquals(FingerprintSignals::fromArray($fixture['signals']), $signals);
    }

    public function test_the_envelope_is_single_use(): void
    {
        $fixture = $this->fixture();
        $this->at($fixture['frozenAt']);
        $body = base64_decode($fixture['bodyBase64'], true);

        $this->decoder()->decode($body, $fixture['visitorId']);

        $this->expectException(InvalidPayload::class);

        $this->decoder()->decode($body, $fixture['visitorId']);
    }

    public function test_the_envelope_is_bound_to_the_visitor(): void
    {
        $fixture = $this->fixture();
        $this->at($fixture['frozenAt']);

        $this->expectException(InvalidPayload::class);

        $this->decoder()->decode(base64_decode($fixture['bodyBase64'], true), '22222222-2222-4222-8222-222222222222');
    }

    public function test_the_envelope_expires_with_its_nonce(): void
    {
        $fixture = $this->fixture();
        $this->at('2026-01-01T00:02:00Z');

        $this->expectException(InvalidPayload::class);

        $this->decoder()->decode(base64_decode($fixture['bodyBase64'], true), $fixture['visitorId']);
    }

    public function test_a_tampered_ciphertext_is_rejected(): void
    {
        $fixture = $this->fixture();
        $this->at($fixture['frozenAt']);
        $body = base64_decode($fixture['bodyBase64'], true);
        $body[strlen($body) - 20] = chr(ord($body[strlen($body) - 20]) ^ 1);

        $this->expectException(InvalidPayload::class);

        $this->decoder()->decode($body, $fixture['visitorId']);
    }
}
