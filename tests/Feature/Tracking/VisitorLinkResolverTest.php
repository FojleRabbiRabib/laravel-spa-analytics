<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorLink;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\VisitorLinkResolver;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class VisitorLinkResolverTest extends TestCase
{
    public function test_an_unlinked_id_resolves_to_itself(): void
    {
        $this->assertSame('visitor-a', app(VisitorLinkResolver::class)->resolve('visitor-a'));
    }

    public function test_a_linked_id_resolves_to_its_target(): void
    {
        VisitorLink::factory()->create(['visitor_id' => 'new', 'linked_to' => 'old']);

        $this->assertSame('old', app(VisitorLinkResolver::class)->resolve('new'));
        $this->assertSame('old', app(VisitorLinkResolver::class)->resolve('old'));
    }
}
