<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;

final readonly class VisitorIdentity
{
    public function __construct(
        public string $id,
        public IdentitySource $source,
        public ?Fingerprint $fingerprint = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $source = $data['source'] instanceof IdentitySource
            ? $data['source']
            : IdentitySource::from((string) $data['source']);

        return new self(
            id: (string) $data['id'],
            source: $source,
            fingerprint: isset($data['fingerprint']) ? Fingerprint::fromArray($data['fingerprint']) : null,
        );
    }

    public function withFingerprint(Fingerprint $fingerprint): self
    {
        return new self($this->id, $this->source, $fingerprint);
    }
}
