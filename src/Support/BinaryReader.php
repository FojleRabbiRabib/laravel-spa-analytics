<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Support;

use FojleRabbiRabib\LaravelSpaAnalytics\Exceptions\InvalidPayload;

final class BinaryReader
{
    private int $offset = 0;

    public function __construct(private readonly string $data) {}

    /**
     * Read one unsigned byte.
     *
     * @throws InvalidPayload
     */
    public function u8(): int
    {
        return ord($this->bytes(1));
    }

    /**
     * Read one big-endian unsigned 16-bit integer.
     *
     * @throws InvalidPayload
     */
    public function u16(): int
    {
        $unpacked = unpack('n', $this->bytes(2));

        return $unpacked === false ? throw InvalidPayload::because('unreadable integer') : $unpacked[1];
    }

    /**
     * Read a length-prefixed string (u16 byte length, then the bytes).
     *
     * @throws InvalidPayload
     */
    public function string(): string
    {
        return $this->bytes($this->u16());
    }

    /**
     * Read exactly $length bytes.
     *
     * @throws InvalidPayload
     */
    public function bytes(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->data)) {
            throw InvalidPayload::because('truncated');
        }

        $chunk = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $chunk;
    }

    /**
     * Return every unread byte and move to the end.
     */
    public function remaining(): string
    {
        $rest = substr($this->data, $this->offset);
        $this->offset = strlen($this->data);

        return $rest;
    }

    /**
     * Fail when unread bytes are left.
     *
     * @throws InvalidPayload
     */
    public function assertFinished(): void
    {
        if ($this->offset !== strlen($this->data)) {
            throw InvalidPayload::because('trailing bytes');
        }
    }
}
