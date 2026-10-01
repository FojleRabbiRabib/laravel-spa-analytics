<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class AudienceConfigTest extends TestCase
{
    public function test_the_country_header_is_off_by_default(): void
    {
        $this->assertNull(config('spa-analytics.audience.country_header'));
    }
}
