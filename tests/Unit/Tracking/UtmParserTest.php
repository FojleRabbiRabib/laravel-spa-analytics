<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\UtmParser;
use PHPUnit\Framework\TestCase;

class UtmParserTest extends TestCase
{
    public function test_it_reads_all_five_values(): void
    {
        $utm = (new UtmParser)->parse([
            'utm_source' => 'news',
            'utm_medium' => 'email',
            'utm_campaign' => 'launch',
            'utm_term' => 'shoes',
            'utm_content' => 'hero',
        ]);

        $this->assertSame([
            'utm_source' => 'news',
            'utm_medium' => 'email',
            'utm_campaign' => 'launch',
            'utm_term' => 'shoes',
            'utm_content' => 'hero',
        ], $utm);
    }

    public function test_missing_empty_and_array_values_are_null(): void
    {
        $utm = (new UtmParser)->parse(['utm_source' => '', 'utm_medium' => ['a'], 'other' => 'x']);

        $this->assertNull($utm['utm_source']);
        $this->assertNull($utm['utm_medium']);
        $this->assertNull($utm['utm_campaign']);
        $this->assertNull($utm['utm_term']);
        $this->assertNull($utm['utm_content']);
    }

    public function test_values_are_trimmed_and_capped(): void
    {
        $utm = (new UtmParser)->parse(['utm_source' => '  spaced  ', 'utm_medium' => str_repeat('a', 400)]);

        $this->assertSame('spaced', $utm['utm_source']);
        $this->assertSame(255, mb_strlen((string) $utm['utm_medium']));
    }
}
