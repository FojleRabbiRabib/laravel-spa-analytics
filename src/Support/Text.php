<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Support;

final class Text
{
    private const MAX_HOST_LENGTH = 255;

    /**
     * The text with invalid UTF-8 bytes replaced by "?", tabs and line breaks turned into spaces and the other control
     * characters (NUL included) removed. Scrubbing comes first: a /u pattern on invalid UTF-8 returns null, which a
     * string cast would turn into an empty string.
     */
    public static function strip(string $value): string
    {
        return (string) preg_replace(['/[\t\n\r]/u', '/\p{Cc}/u'], [' ', ''], mb_scrub($value));
    }

    /**
     * A string field made safe to store: scrubbed, stripped, trimmed and cut to the length. Null when it is not a
     * string or nothing is left.
     */
    public static function clean(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = mb_substr(trim(self::strip($value)), 0, $max);

        return $clean === '' ? null : $clean;
    }

    /**
     * A host name in lower case without a trailing dot, or null when it is not a valid host name, an IPv4 address or a
     * bracketed IPv6 address, or is longer than the 255 characters the columns hold. Internationalised names are
     * allowed, including combining marks and joiners, and so are underscores, which real hosts use.
     */
    public static function host(?string $host): ?string
    {
        if ($host === null || ! mb_check_encoding($host, 'UTF-8')) {
            return null;
        }

        $host = rtrim(mb_strtolower($host), '.');

        if (mb_strlen($host) > self::MAX_HOST_LENGTH) {
            return null;
        }

        return preg_match('/^(?:\[[0-9a-f:.]+\]|[\p{L}\p{N}\p{M}](?:[\p{L}\p{N}\p{M}\x{200C}\x{200D}._-]*[\p{L}\p{N}\p{M}])?)$/u', $host) === 1 ? $host : null;
    }
}
