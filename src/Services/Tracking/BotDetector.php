<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

class BotDetector
{
    /**
     * @param  array<int, string>  $patterns  Case-insensitive user agent substrings.
     */
    public function __construct(private readonly array $patterns) {}

    /**
     * Whether the user agent looks like a bot; a missing user agent counts as one.
     */
    public function isBot(?string $userAgent): bool
    {
        if ($userAgent === null || $userAgent === '') {
            return true;
        }

        foreach ($this->patterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
