<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Exceptions;

final class InvalidPayload extends \RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('Invalid identity payload: '.$reason);
    }
}
