<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Support;

use FojleRabbiRabib\LaravelSpaAnalytics\Support\VisitorId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VisitorIdTest extends TestCase
{
    public function test_a_uuid_is_returned_in_lower_case(): void
    {
        $this->assertSame(
            '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
            VisitorId::normalize('0A1B2C3D-4E5F-4A6B-8C7D-9E0F1A2B3C4D'),
        );
    }

    public function test_a_lower_case_uuid_is_unchanged(): void
    {
        $this->assertSame(
            '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
            VisitorId::normalize('0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d'),
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notVisitorIds(): array
    {
        return [
            'null' => [null],
            'array' => [['0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d']],
            'text' => ['not-a-uuid'],
            'empty' => [''],
            'padded' => [' 0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d'],
        ];
    }

    #[DataProvider('notVisitorIds')]
    public function test_anything_else_is_not_a_visitor_id(mixed $value): void
    {
        $this->assertNull(VisitorId::normalize($value));
    }
}
