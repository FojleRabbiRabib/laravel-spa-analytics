<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorFingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Database\UniqueConstraintViolationException;

class VisitorFingerprintModelTest extends TestCase
{
    public function test_factory_persists_a_fingerprint_with_date_casts(): void
    {
        $fingerprint = VisitorFingerprint::factory()->create()->fresh();

        $this->assertNotNull($fingerprint->first_seen_at);
        $this->assertNotNull($fingerprint->last_seen_at);
        $this->assertNull($fingerprint->tls_hash);
    }

    public function test_visitor_id_is_unique(): void
    {
        VisitorFingerprint::factory()->create(['visitor_id' => 'same']);

        $this->expectException(UniqueConstraintViolationException::class);

        VisitorFingerprint::factory()->create(['visitor_id' => 'same']);
    }
}
