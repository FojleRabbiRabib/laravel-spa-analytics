<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Enums;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use PHPUnit\Framework\TestCase;

class RollupDimensionTest extends TestCase
{
    public function test_helpers(): void
    {
        $this->assertTrue(RollupDimension::Total->is(RollupDimension::Total));
        $this->assertFalse(RollupDimension::Total->is(RollupDimension::Path));
        $this->assertSame('Total', RollupDimension::Total->label());
        $this->assertSame('Referrer type', RollupDimension::ReferrerType->label());
        $this->assertSame('Visitor type', RollupDimension::VisitorType->label());
        $this->assertSame('gray', RollupDimension::Total->color());
        $this->assertSame('blue', RollupDimension::Path->color());
        $this->assertSame('blue', RollupDimension::ExitPath->color());
        $this->assertSame(RollupDimension::Total, RollupDimension::default());
    }

    public function test_values_are_the_stored_dimension_names(): void
    {
        $this->assertSame([
            'total', 'path', 'exit_path', 'referrer_type', 'referrer_host', 'utm_campaign', 'device_type',
            'os', 'browser', 'country', 'visitor_type', 'event', 'goal', 'outbound_host', 'scroll_depth',
            'utm_source', 'utm_medium', 'utm_term', 'utm_content',
        ], RollupDimension::values());
        $this->assertCount(19, RollupDimension::options());
        $this->assertSame('UTM source', RollupDimension::UtmSource->label());
        $this->assertSame('UTM content', RollupDimension::UtmContent->label());
        $this->assertSame('purple', RollupDimension::UtmMedium->color());
        $this->assertSame('utm_term', RollupDimension::UtmTerm->sessionColumn());
        $this->assertSame('Outbound host', RollupDimension::OutboundHost->label());
        $this->assertSame('Scroll depth', RollupDimension::ScrollDepth->label());
        $this->assertSame('orange', RollupDimension::OutboundHost->color());
        $this->assertSame(['value' => 'exit_path', 'label' => 'Exit path'], RollupDimension::options()[2]);
        $this->assertSame(['value' => 'path', 'label' => 'Path'], RollupDimension::options()[1]);
    }
}
