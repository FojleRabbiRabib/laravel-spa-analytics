<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Enums;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ClientEventKind;
use PHPUnit\Framework\TestCase;

class ClientEventKindTest extends TestCase
{
    public function test_helpers(): void
    {
        $this->assertTrue(ClientEventKind::Goal->is(ClientEventKind::Goal));
        $this->assertFalse(ClientEventKind::Goal->is(ClientEventKind::Event));
        $this->assertSame('Outbound click', ClientEventKind::Outbound->label());
        $this->assertSame('orange', ClientEventKind::Outbound->color());
        $this->assertSame(['pageview', 'outbound', 'scroll', 'event', 'goal'], ClientEventKind::values());
        $this->assertSame(ClientEventKind::PageView, ClientEventKind::default());
        $this->assertSame(['value' => 'scroll', 'label' => 'Scroll depth'], ClientEventKind::options()[2]);
    }
}
