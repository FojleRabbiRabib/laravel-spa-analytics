<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity;

final readonly class Handshake
{
    public function __construct(
        public string $nonce,
        public string $key,
        public int $expiresAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['nonce'], (string) $data['key'], (int) $data['expiresAt']);
    }
}
