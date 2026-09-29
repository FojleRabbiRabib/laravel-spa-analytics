<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;

final readonly class Referrer
{
    public function __construct(
        public ?string $host,
        public ReferrerType $type,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] instanceof ReferrerType
            ? $data['type']
            : ReferrerType::from((string) $data['type']);

        return new self(
            host: isset($data['host']) ? (string) $data['host'] : null,
            type: $type,
        );
    }
}
