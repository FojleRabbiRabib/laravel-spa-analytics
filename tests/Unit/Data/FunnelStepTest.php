<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Data;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\FunnelStep;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\FunnelStepType;
use PHPUnit\Framework\TestCase;

class FunnelStepTest extends TestCase
{
    public function test_from_array_builds_a_step_with_a_default_label(): void
    {
        $goal = FunnelStep::fromArray(['type' => 'goal', 'value' => 'signup', 'label' => 'Signed up']);
        $prefix = FunnelStep::fromArray(['type' => 'path_prefix', 'value' => '/blog/']);

        $this->assertSame(FunnelStepType::Goal, $goal->type);
        $this->assertSame('Signed up', $goal->label);
        $this->assertSame('/blog/*', $prefix->label);
    }

    public function test_from_array_rejects_an_unknown_type_and_an_empty_value(): void
    {
        try {
            FunnelStep::fromArray(['type' => 'click', 'value' => 'x']);
            $this->fail('An unknown type should be rejected.');
        } catch (\ValueError) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);

        FunnelStep::fromArray(['type' => 'path']);
    }

    public function test_a_step_only_matches_its_own_kind_of_event_exactly(): void
    {
        $this->assertTrue(FunnelStep::path('/a')->matches('page_view', '/a', null));
        $this->assertFalse(FunnelStep::path('/a')->matches('page_view', '/A', null));
        $this->assertFalse(FunnelStep::path('/a')->matches('custom', '/a', null));
        $this->assertTrue(FunnelStep::pathStartingWith('/a_b')->matches('page_view', '/a_b/c', null));
        $this->assertFalse(FunnelStep::pathStartingWith('/a_b')->matches('page_view', '/axb', null));
        $this->assertFalse(FunnelStep::goal('signup')->matches('custom', null, 'signup'));
        $this->assertTrue(FunnelStep::event('signup')->matches('custom', null, 'signup'));
    }
}
