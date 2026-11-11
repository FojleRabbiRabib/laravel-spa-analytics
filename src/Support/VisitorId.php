<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Support;

use Illuminate\Support\Str;

final class VisitorId
{
    /**
     * The visitor id in its one stored form, lower case, or null when the value is not a UUID. A UUID is valid in any
     * letter case, but the lock key, the rate limit key and a case-sensitive database column all treat each case as
     * another visitor.
     */
    public static function normalize(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }
}
