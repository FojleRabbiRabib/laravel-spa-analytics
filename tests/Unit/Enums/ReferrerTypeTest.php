<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Enums;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use PHPUnit\Framework\TestCase;

class ReferrerTypeTest extends TestCase
{
    public function test_helpers(): void
    {
        $this->assertTrue(ReferrerType::Search->is(ReferrerType::Search));
        $this->assertFalse(ReferrerType::Search->is(ReferrerType::Social));
        $this->assertSame('Referral', ReferrerType::Referral->label());
        $this->assertSame('gray', ReferrerType::Direct->color());
        $this->assertSame('green', ReferrerType::Search->color());
        $this->assertSame('purple', ReferrerType::Social->color());
        $this->assertSame('amber', ReferrerType::Referral->color());
        $this->assertSame(['direct', 'search', 'social', 'referral'], ReferrerType::values());
        $this->assertSame(ReferrerType::Direct, ReferrerType::default());
        $this->assertSame(
            [
                ['value' => 'direct', 'label' => 'Direct'],
                ['value' => 'search', 'label' => 'Search'],
                ['value' => 'social', 'label' => 'Social'],
                ['value' => 'referral', 'label' => 'Referral'],
            ],
            ReferrerType::options(),
        );
    }
}
