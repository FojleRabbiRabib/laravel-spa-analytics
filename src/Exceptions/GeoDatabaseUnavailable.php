<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Exceptions;

final class GeoDatabaseUnavailable extends \RuntimeException
{
    /**
     * The maxmind-db/reader library is not installed.
     */
    public static function libraryMissing(): self
    {
        return new self('The maxmind-db/reader package is not installed; run composer require maxmind-db/reader.');
    }

    /**
     * The configured database file cannot be read as a MaxMind database.
     */
    public static function unreadable(string $path): self
    {
        return new self('The MaxMind database at "'.$path.'" is missing or not a valid .mmdb file.');
    }
}
