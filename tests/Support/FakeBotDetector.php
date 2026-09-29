<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\BotDetector;

final class FakeBotDetector implements BotDetector
{
    public function isBot(?string $userAgent): bool
    {
        return true;
    }
}
