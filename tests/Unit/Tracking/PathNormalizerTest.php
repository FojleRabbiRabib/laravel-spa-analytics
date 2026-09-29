<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\PathNormalizer;
use PHPUnit\Framework\TestCase;

class PathNormalizerTest extends TestCase
{
    public function test_it_strips_query_and_fragment(): void
    {
        $this->assertSame('/a/b', (new PathNormalizer)->normalize('/a/b?x=1'));
        $this->assertSame('/a/b', (new PathNormalizer)->normalize('//a//b#f'));
    }

    public function test_it_ensures_a_leading_slash(): void
    {
        $this->assertSame('/a', (new PathNormalizer)->normalize('a'));
        $this->assertSame('/', (new PathNormalizer)->normalize(''));
        $this->assertSame('/', (new PathNormalizer)->normalize('?x=1'));
    }

    public function test_it_caps_the_length(): void
    {
        $this->assertSame(512, mb_strlen((new PathNormalizer)->normalize('/'.str_repeat('a', 2000))));
    }
}
