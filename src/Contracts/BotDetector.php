<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Contracts;

interface BotDetector
{
    /**
     * Whether the user agent belongs to a bot; each implementation decides how a missing user agent is treated.
     */
    public function isBot(?string $userAgent): bool;
}
