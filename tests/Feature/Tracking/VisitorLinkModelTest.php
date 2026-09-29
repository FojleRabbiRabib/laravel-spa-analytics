<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorLink;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Database\UniqueConstraintViolationException;

class VisitorLinkModelTest extends TestCase
{
    public function test_factory_persists_a_link_with_date_cast(): void
    {
        $link = VisitorLink::factory()->create()->fresh();

        $this->assertNotNull($link->created_at);
        $this->assertNotSame($link->visitor_id, $link->linked_to);
    }

    public function test_visitor_id_is_unique(): void
    {
        VisitorLink::factory()->create(['visitor_id' => 'same']);

        $this->expectException(UniqueConstraintViolationException::class);

        VisitorLink::factory()->create(['visitor_id' => 'same']);
    }
}
