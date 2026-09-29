<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Enums;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;
use PHPUnit\Framework\TestCase;

class IdentitySourceTest extends TestCase
{
    public function test_helpers(): void
    {
        $this->assertTrue(IdentitySource::Cookie->is(IdentitySource::Cookie));
        $this->assertFalse(IdentitySource::Cookie->is(IdentitySource::Generated));
        $this->assertSame('Cookie', IdentitySource::Cookie->label());
        $this->assertSame('green', IdentitySource::Cookie->color());
        $this->assertSame(['cookie', 'generated'], IdentitySource::values());
        $this->assertSame(IdentitySource::Generated, IdentitySource::default());
        $this->assertSame(
            [['value' => 'cookie', 'label' => 'Cookie'], ['value' => 'generated', 'label' => 'Generated']],
            IdentitySource::options(),
        );
    }
}
