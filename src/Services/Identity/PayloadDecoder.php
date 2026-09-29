<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\FingerprintSignals;
use FojleRabbiRabib\LaravelSpaAnalytics\Exceptions\InvalidPayload;
use FojleRabbiRabib\LaravelSpaAnalytics\Support\BinaryReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class PayloadDecoder
{
    private const VERSION = 1;

    private const TAG_LENGTH = 16;

    private const MAX_BODY_BYTES = 2048;

    private const MAX_PLAINTEXT_BYTES = 4096;

    public function __construct(private readonly HandshakeTokenIssuer $issuer) {}

    /**
     * @throws InvalidPayload
     */
    public function decode(string $body, string $visitorId): FingerprintSignals
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw InvalidPayload::because('body too large');
        }

        $reader = new BinaryReader($body);

        if ($reader->u8() !== self::VERSION) {
            throw InvalidPayload::because('unsupported version');
        }

        $nonce = $reader->bytes($reader->u16());
        $iv = $reader->bytes(12);
        $rest = $reader->remaining();

        if (strlen($rest) <= self::TAG_LENGTH) {
            throw InvalidPayload::because('missing ciphertext');
        }

        $nonceId = $this->issuer->verify($nonce, $visitorId) ?? throw InvalidPayload::because('invalid nonce');

        $ttl = (int) config('laravel-spa-analytics.identity.nonce_ttl_seconds') + 5;

        if (! Cache::add('identity-nonce:'.bin2hex($nonceId), true, $ttl)) {
            throw InvalidPayload::because('nonce already used');
        }

        $plain = openssl_decrypt(
            substr($rest, 0, -self::TAG_LENGTH),
            'aes-256-gcm',
            $this->issuer->encryptionKey($nonceId),
            OPENSSL_RAW_DATA,
            $iv,
            substr($rest, -self::TAG_LENGTH),
            $nonce,
        );

        if ($plain === false) {
            throw InvalidPayload::because('decryption failed');
        }

        $inflated = @gzdecode($plain, self::MAX_PLAINTEXT_BYTES);

        if ($inflated === false) {
            throw InvalidPayload::because('decompression failed');
        }

        return $this->decodePlaintext($inflated);
    }

    /**
     * @throws InvalidPayload
     */
    public function decodePlaintext(string $bytes): FingerprintSignals
    {
        $reader = new BinaryReader($bytes);

        if ($reader->u8() !== self::VERSION) {
            throw InvalidPayload::because('unsupported version');
        }

        $data = [
            'screenWidth' => $reader->u16(),
            'screenHeight' => $reader->u16(),
            'colorDepth' => $reader->u16(),
            'timezone' => $reader->string(),
            'hardwareConcurrency' => $reader->u16(),
            'deviceMemory' => $reader->u16(),
            'platform' => $reader->string(),
            'languages' => $reader->string(),
            'maxTouchPoints' => $reader->u16(),
            'webglVendor' => $reader->string(),
            'webglRenderer' => $reader->string(),
            'canvasHash' => $reader->string(),
            'audioHash' => $reader->string(),
        ];

        $reader->assertFinished();

        $validator = Validator::make($data, [
            'screenWidth' => ['integer', 'between:0,20000'],
            'screenHeight' => ['integer', 'between:0,20000'],
            'colorDepth' => ['integer', 'between:0,128'],
            'timezone' => ['string', 'max:64'],
            'hardwareConcurrency' => ['integer', 'between:0,1024'],
            'deviceMemory' => ['integer', 'between:0,65535'],
            'platform' => ['string', 'max:64'],
            'languages' => ['string', 'max:128'],
            'maxTouchPoints' => ['integer', 'between:0,64'],
            'webglVendor' => ['string', 'max:128'],
            'webglRenderer' => ['string', 'max:128'],
            'canvasHash' => ['string', 'regex:/^([a-f0-9]{64})?$/'],
            'audioHash' => ['string', 'regex:/^([a-f0-9]{64})?$/'],
        ]);

        if ($validator->fails()) {
            throw InvalidPayload::because('schema violation');
        }

        return FingerprintSignals::fromArray($data);
    }
}
