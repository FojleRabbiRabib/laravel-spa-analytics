<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\BotDetector;
use PHPUnit\Framework\TestCase;

class BotDetectorTest extends TestCase
{
    private function detector(): BotDetector
    {
        return new BotDetector(['bot', 'headless', 'curl']);
    }

    public function test_known_bots_are_detected(): void
    {
        $this->assertTrue($this->detector()->isBot('Mozilla/5.0 (compatible; Googlebot/2.1)'));
        $this->assertTrue($this->detector()->isBot('Mozilla/5.0 HeadlessChrome/120.0'));
        $this->assertTrue($this->detector()->isBot('curl/8.4.0'));
    }

    public function test_missing_user_agent_counts_as_bot(): void
    {
        $this->assertTrue($this->detector()->isBot(null));
        $this->assertTrue($this->detector()->isBot(''));
    }

    public function test_regular_browsers_are_not_bots(): void
    {
        $this->assertFalse($this->detector()->isBot('Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0'));
    }
}
