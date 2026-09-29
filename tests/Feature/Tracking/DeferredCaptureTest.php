<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Models\Event;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Session;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DeferredCaptureTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function ($router): void {
            $router->get('/page', fn () => '<html>ok</html>');
            $router->get('/missing', fn () => abort(404));
            $router->get('/boom', fn () => throw new \RuntimeException('boom'));
        });
    }

    public function test_default_defer_mode_records_success_and_error_responses_through_the_real_stack(): void
    {
        $this->assertSame('defer', config('spa-analytics.tracking.write_mode'));

        $this->withCookie('spa_analytics_vid', '22222222-2222-4222-8222-222222222222');

        $this->get('/page')->assertOk();
        $this->get('/missing')->assertNotFound();
        $this->get('/boom')->assertStatus(500);

        $this->assertSame([200, 404, 500], Event::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(1, Session::query()->count());
        $this->assertSame(3, Session::query()->sole()->page_views);
    }

    public function test_default_defer_mode_survives_a_failing_insert(): void
    {
        Log::spy();
        Schema::drop('analytics_events');

        $this->get('/page')->assertOk();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => ! str_contains(json_encode($context), '127.0.0.1'),
        );
    }
}
