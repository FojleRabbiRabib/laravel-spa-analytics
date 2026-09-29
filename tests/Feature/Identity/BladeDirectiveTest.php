<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class BladeDirectiveTest extends TestCase
{
    public function test_directive_renders_the_collector_script_with_endpoints(): void
    {
        $html = Blade::render('@spaAnalytics', deleteCachedView: true);

        $this->assertStringContainsString('vendor/laravel-spa-analytics/client.js', $html);
        $this->assertStringContainsString('data-handshake="'.route('spa-analytics.identity.handshake').'"', $html);
        $this->assertStringContainsString('data-identify="'.route('spa-analytics.identity.identify').'"', $html);
    }

    public function test_directive_renders_nothing_when_disabled(): void
    {
        config()->set('laravel-spa-analytics.enabled', false);

        $this->assertSame('', trim(Blade::render('@spaAnalytics', deleteCachedView: true)));
    }

    public function test_assets_publish_tag_is_registered(): void
    {
        $this->assertArrayHasKey('spa-analytics-assets', ServiceProvider::$publishGroups);
    }
}
