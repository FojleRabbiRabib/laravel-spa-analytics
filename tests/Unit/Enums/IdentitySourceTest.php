<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Enums;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;
use PHPUnit\Framework\TestCase;

class IdentitySourceTest extends TestCase
{
    public function test_is_label_color_values_default_and_options_describe_the_cases(): void
    {
        $this->assertTrue(IdentitySource::Cookie->is(IdentitySource::Cookie));
        $this->assertFalse(IdentitySource::Cookie->is(IdentitySource::Generated));
        $this->assertSame('Cookie', IdentitySource::Cookie->label());
        $this->assertSame('green', IdentitySource::Cookie->color());
        $this->assertSame('Relinked', IdentitySource::Relinked->label());
        $this->assertSame('blue', IdentitySource::Relinked->color());
        $this->assertTrue(IdentitySource::Relinked->is(IdentitySource::Relinked));
        $this->assertSame(['cookie', 'generated', 'relinked'], IdentitySource::values());
        $this->assertSame(IdentitySource::Generated, IdentitySource::default());
        $this->assertSame(
            [
                ['value' => 'cookie', 'label' => 'Cookie'],
                ['value' => 'generated', 'label' => 'Generated'],
                ['value' => 'relinked', 'label' => 'Relinked'],
            ],
            IdentitySource::options(),
        );
    }
}
