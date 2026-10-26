<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Http\Controllers\CollectEventsController;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Controllers\IdentifyVisitorController;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Controllers\IdentityHandshakeController;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware\ResolveVisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RoutesOffTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.identity.register_routes', false);
        $app['config']->set('spa-analytics.tracking.write_mode', 'sync');
    }

    private function ownRoutes(): void
    {
        Route::middleware(['web', ResolveVisitorIdentity::class, 'throttle:spa-analytics-identity'])
            ->prefix('my-analytics')
            ->name('spa-analytics.identity.')
            ->group(function (): void {
                Route::post('hello', IdentityHandshakeController::class)->name('handshake');
                Route::post('who', IdentifyVisitorController::class)->name('identify');
            });

        Route::middleware(['web', ResolveVisitorIdentity::class, 'throttle:spa-analytics-collect'])
            ->prefix('my-analytics')
            ->name('spa-analytics.')
            ->group(function (): void {
                Route::post('send', CollectEventsController::class)->name('collect');
            });

        Route::getRoutes()->refreshNameLookups();
    }

    private function render(): string
    {
        return Blade::render('@spaAnalytics', deleteCachedView: true);
    }

    private function aboutRoutes(): string
    {
        Artisan::call('about', ['--json' => true]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)[Str::snake('SPA Analytics')]['routes'];
    }

    public function test_no_package_route_is_registered(): void
    {
        foreach (['spa-analytics.identity.handshake', 'spa-analytics.identity.identify', 'spa-analytics.collect'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }

        $this->postJson('/spa-analytics/collect', ['events' => []])->assertNotFound();
        $this->postJson('/spa-analytics/handshake')->assertNotFound();
        $this->postJson('/spa-analytics/identify')->assertNotFound();
    }

    public function test_about_shows_off_while_tracking_is_off_even_without_routes(): void
    {
        config()->set('spa-analytics.enabled', false);

        $this->assertSame('OFF', $this->aboutRoutes());
    }

    public function test_the_directive_renders_nothing_and_never_throws_while_the_routes_are_missing(): void
    {
        $this->assertSame('', trim($this->render()));
        $this->assertSame('MISSING', $this->aboutRoutes());
    }

    public function test_the_directive_stays_empty_when_only_some_routes_exist(): void
    {
        Route::post('/only-collect', fn () => response()->noContent())->name('spa-analytics.collect');
        Route::getRoutes()->refreshNameLookups();

        $this->assertSame('', trim($this->render()));
        $this->assertSame('MISSING', $this->aboutRoutes());
    }

    public function test_an_app_can_register_its_own_routes_under_the_same_names(): void
    {
        $this->ownRoutes();

        $html = $this->render();

        $this->assertStringContainsString('data-handshake="'.url('/my-analytics/hello').'"', $html);
        $this->assertStringContainsString('data-identify="'.url('/my-analytics/who').'"', $html);
        $this->assertStringContainsString('data-collect="'.url('/my-analytics/send').'"', $html);
        $this->assertSame('own', $this->aboutRoutes());
    }

    public function test_the_own_routes_work_end_to_end(): void
    {
        $this->ownRoutes();

        $this->postJson('/my-analytics/hello')->assertOk();

        $this->withCredentials()->withCookie('spa_analytics_vid', '11111111-1111-4111-8111-111111111111')
            ->postJson('/my-analytics/send', ['events' => [['kind' => 'pageview', 'path' => '/docs']]])
            ->assertNoContent();

        $this->assertSame('/docs', AnalyticsEvent::query()->sole()->path);
    }
}
