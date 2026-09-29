<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Event;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Session;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support\IdentifiesVisitors;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class RelinkFlowTest extends TestCase
{
    use IdentifiesVisitors;

    private const RETURNING = '11111111-1111-4111-8111-111111111111';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.tracking.write_mode', 'sync');
        $app['config']->set('spa-analytics.identity.relink', true);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/page', fn () => '<html>ok</html>');
    }

    public function test_a_returning_visitor_who_lost_the_cookie_ends_up_under_one_id(): void
    {
        $this->withCookie('spa_analytics_vid', self::RETURNING)->get('/page')->assertOk();
        $this->identifyAs(self::RETURNING)->assertOk();

        $this->defaultCookies = [];
        $fresh = $this->get('/page')->assertOk();
        $newId = $fresh->getCookie('spa_analytics_vid')->getValue();

        $this->assertNotSame(self::RETURNING, $newId);
        $this->assertSame(2, Session::query()->count());

        $this->identifyAs($newId)->assertOk()->assertJsonPath('id', self::RETURNING);

        $this->assertSame([self::RETURNING], Event::query()->pluck('visitor_id')->unique()->values()->all());
        $this->assertSame([self::RETURNING], Session::query()->pluck('visitor_id')->unique()->values()->all());
        $this->assertSame(1, Session::query()->where('is_new_visitor', true)->count());

        app(EventStore::class)->store(new PageViewData(
            type: EventType::PageView,
            visitorId: $newId,
            path: '/late',
            status: 200,
            referrerHost: null,
            referrerType: ReferrerType::Direct,
            utm: ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_term' => null, 'utm_content' => null],
            language: null,
            ip: null,
            userAgent: 'Mozilla/5.0',
            isBot: false,
            occurredAt: CarbonImmutable::now(),
        ));

        $this->assertSame(0, Event::query()->where('visitor_id', $newId)->count());
        $this->assertSame(0, Session::query()->where('visitor_id', $newId)->count());
        $this->assertSame(1, Event::query()->where('path', '/late')->where('visitor_id', self::RETURNING)->count());
    }
}
