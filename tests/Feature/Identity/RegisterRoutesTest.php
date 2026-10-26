<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RegisterRoutesTest extends TestCase
{
    public function test_the_routes_are_registered_by_default(): void
    {
        $this->assertTrue(config('spa-analytics.identity.register_routes'));

        foreach (['spa-analytics.identity.handshake', 'spa-analytics.identity.identify', 'spa-analytics.collect'] as $name) {
            $this->assertTrue(Route::has($name), $name);
        }
    }

    public function test_about_reports_the_package_routes(): void
    {
        Artisan::call('about', ['--json' => true]);

        $section = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)[Str::snake('SPA Analytics')];

        $this->assertSame('package', $section['routes']);
    }

    public function test_about_shows_missing_when_the_flag_is_on_but_the_routes_are_gone(): void
    {
        Route::setRoutes(new RouteCollection);
        Route::getRoutes()->refreshNameLookups();

        Artisan::call('about', ['--json' => true]);

        $section = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)[Str::snake('SPA Analytics')];

        $this->assertSame('MISSING', $section['routes']);
    }

    public function test_the_directive_renders_the_script_with_the_package_routes(): void
    {
        $this->assertStringContainsString('data-collect="'.route('spa-analytics.collect').'"', Blade::render('@spaAnalytics', deleteCachedView: true));
    }
}
