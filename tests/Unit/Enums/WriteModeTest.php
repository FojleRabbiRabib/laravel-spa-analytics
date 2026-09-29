<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Enums;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\WriteMode;
use PHPUnit\Framework\TestCase;

class WriteModeTest extends TestCase
{
    public function test_helpers(): void
    {
        $this->assertTrue(WriteMode::Defer->is(WriteMode::Defer));
        $this->assertFalse(WriteMode::Defer->is(WriteMode::Sync));
        $this->assertSame('Queue', WriteMode::Queue->label());
        $this->assertSame('green', WriteMode::Defer->color());
        $this->assertSame('amber', WriteMode::Queue->color());
        $this->assertSame('gray', WriteMode::Sync->color());
        $this->assertSame(['defer', 'queue', 'sync'], WriteMode::values());
        $this->assertSame(WriteMode::Defer, WriteMode::default());
        $this->assertSame(
            [
                ['value' => 'defer', 'label' => 'Defer'],
                ['value' => 'queue', 'label' => 'Queue'],
                ['value' => 'sync', 'label' => 'Sync'],
            ],
            WriteMode::options(),
        );
    }
}
