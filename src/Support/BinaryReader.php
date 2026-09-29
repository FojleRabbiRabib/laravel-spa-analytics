<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Support;

use FojleRabbiRabib\LaravelSpaAnalytics\Exceptions\InvalidPayload;

final class BinaryReader
{
    private int $offset = 0;

    public function __construct(private readonly string $data) {}

    public function u8(): int
    {
        return ord($this->bytes(1));
    }

    public function u16(): int
    {
        $unpacked = unpack('n', $this->bytes(2));

        return $unpacked === false ? throw InvalidPayload::because('unreadable integer') : $unpacked[1];
    }

    public function string(): string
    {
        return $this->bytes($this->u16());
    }

    public function bytes(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->data)) {
            throw InvalidPayload::because('truncated');
        }

        $chunk = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $chunk;
    }

    public function remaining(): string
    {
        $rest = substr($this->data, $this->offset);
        $this->offset = strlen($this->data);

        return $rest;
    }

    public function assertFinished(): void
    {
        if ($this->offset !== strlen($this->data)) {
            throw InvalidPayload::because('trailing bytes');
        }
    }
}
