<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\BotDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\LaravelSpaAnalyticsServiceProvider;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support\FakeBotDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support\FakeEventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support\OverridingServiceProvider;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class ContractOverrideTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelSpaAnalyticsServiceProvider::class,
            OverridingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.tracking.write_mode', 'sync');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/page', fn () => '<html>ok</html>');
    }

    protected function setUp(): void
    {
        parent::setUp();

        FakeEventStore::$stored = [];
    }

    public function test_an_app_binding_made_in_register_wins_over_the_package_default(): void
    {
        $this->assertInstanceOf(FakeBotDetector::class, app(BotDetector::class));
        $this->assertInstanceOf(FakeEventStore::class, app(EventStore::class));
    }

    public function test_the_capture_path_uses_the_swapped_implementations(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Firefox/121.0'])
            ->get('/page')
            ->assertOk();

        $this->assertCount(1, FakeEventStore::$stored);
        $this->assertTrue(FakeEventStore::$stored[0]->isBot);
        $this->assertSame(0, AnalyticsEvent::query()->count());
    }
}
