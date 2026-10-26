<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

class PublishedConfigRoutesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.identity', Arr::except((require __DIR__.'/../../../config/spa-analytics.php')['identity'], 'register_routes'));
    }

    public function test_a_published_config_without_the_key_keeps_the_routes_on(): void
    {
        $this->assertNull(config('spa-analytics.identity.register_routes'));

        foreach (['spa-analytics.identity.handshake', 'spa-analytics.identity.identify', 'spa-analytics.collect'] as $name) {
            $this->assertTrue(Route::has($name), $name);
        }

        $this->assertStringContainsString('data-collect="'.route('spa-analytics.collect').'"', Blade::render('@spaAnalytics', deleteCachedView: true));
    }
}
