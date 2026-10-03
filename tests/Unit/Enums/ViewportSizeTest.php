<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Enums;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ViewportSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ViewportSizeTest extends TestCase
{
    /**
     * @return array<string, array{int, ?ViewportSize}>
     */
    public static function widths(): array
    {
        return [
            '1' => [1, ViewportSize::Xs],
            '575' => [575, ViewportSize::Xs],
            '576' => [576, ViewportSize::Sm],
            '767' => [767, ViewportSize::Sm],
            '768' => [768, ViewportSize::Md],
            '991' => [991, ViewportSize::Md],
            '992' => [992, ViewportSize::Lg],
            '1199' => [1199, ViewportSize::Lg],
            '1200' => [1200, ViewportSize::Xl],
            '10000' => [10000, ViewportSize::Xl],
            '10001' => [10001, null],
            '0' => [0, null],
            '-20' => [-20, null],
        ];
    }

    #[DataProvider('widths')]
    public function test_a_width_falls_into_its_size_class(int $width, ?ViewportSize $expected): void
    {
        $this->assertSame($expected, ViewportSize::fromWidth($width));
    }

    public function test_helpers(): void
    {
        $this->assertTrue(ViewportSize::Md->is(ViewportSize::Md));
        $this->assertFalse(ViewportSize::Md->is(ViewportSize::Lg));
        $this->assertSame('Under 576 px', ViewportSize::Xs->label());
        $this->assertSame('1200 px and wider', ViewportSize::Xl->label());
        $this->assertSame('purple', ViewportSize::Md->color());
        $this->assertSame(['xs', 'sm', 'md', 'lg', 'xl'], ViewportSize::values());
        $this->assertSame(['value' => 'sm', 'label' => '576 to 767 px'], ViewportSize::options()[1]);
        $this->assertSame(ViewportSize::Lg, ViewportSize::coerce('lg'));
        $this->assertSame(ViewportSize::Lg, ViewportSize::coerce(ViewportSize::Lg));
        $this->assertSame(ViewportSize::Md, ViewportSize::default());
    }
}
