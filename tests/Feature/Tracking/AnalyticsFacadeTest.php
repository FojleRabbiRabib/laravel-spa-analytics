<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\CustomEventData;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Analytics;
use FojleRabbiRabib\LaravelSpaAnalytics\Jobs\WriteEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\AnalyticsTracker;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

class AnalyticsFacadeTest extends TestCase
{
    private const VISITOR = '11111111-1111-4111-8111-111111111111';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.tracking.write_mode', 'sync');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->post('/track', function (Request $request) {
            Analytics::track((string) $request->input('name'), (array) $request->input('properties', []));

            return 'ok';
        });

        $router->middleware('web')->post('/goal', function (Request $request) {
            Analytics::goal(
                (string) $request->input('name'),
                $request->has('value') ? (float) $request->input('value') : null,
            );

            return 'ok';
        });

        $router->middleware('web')->post('/webhook', function (Request $request) {
            Analytics::for((string) $request->input('visitor'))->goal('purchase', 10.0);

            return 'ok';
        });
    }

    private function track(string $name, array $properties = []): void
    {
        $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)
            ->withHeaders(['Accept-Language' => 'bn-BD,bn;q=0.9', 'User-Agent' => 'Mozilla/5.0 (X11; Linux) Firefox/121.0'])
            ->postJson('/track', ['name' => $name, 'properties' => $properties])
            ->assertOk();
    }

    public function test_track_records_a_custom_event_from_the_current_request(): void
    {
        $this->track('signup_clicked', ['plan' => 'pro']);

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(EventType::Custom, $event->type);
        $this->assertSame(self::VISITOR, $event->visitor_id);
        $this->assertSame('signup_clicked', $event->name);
        $this->assertSame(['plan' => 'pro'], $event->properties);
        $this->assertSame('/track', $event->path);
        $this->assertNull($event->status);
        $this->assertSame('bn-BD', $event->language);
        $this->assertSame('Mozilla/5.0 (X11; Linux) Firefox/121.0', $event->user_agent);
        $this->assertNotNull($event->ip);
        $this->assertFalse($event->is_bot);
    }

    public function test_goal_records_its_value(): void
    {
        $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)->postJson('/goal', ['name' => 'purchase', 'value' => 49.5])->assertOk();

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(EventType::Goal, $event->type);
        $this->assertSame('49.50', $event->value);
    }

    public function test_a_bot_user_agent_flags_the_event(): void
    {
        $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)
            ->withHeaders(['User-Agent' => 'Googlebot/2.1'])
            ->postJson('/track', ['name' => 'signup_clicked'])
            ->assertOk();

        $this->assertTrue(AnalyticsEvent::query()->sole()->is_bot);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'too long' => [str_repeat('a', 129)],
            'space' => ['has space'],
            'slash' => ['a/b'],
            'unicode' => ['café'],
        ];
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names_record_nothing(string $name): void
    {
        Analytics::for(self::VISITOR)->track($name);

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_valid_name_characters_are_accepted(): void
    {
        Analytics::for(self::VISITOR)->track('Checkout.completed:v2-final_1');
        Analytics::for(self::VISITOR)->track(str_repeat('a', 128));

        $this->assertSame(2, AnalyticsEvent::query()->count());
    }

    /**
     * @return array<string, array{float, ?string}>
     */
    public static function values(): array
    {
        return [
            'negative' => [-1.0, null],
            'nan' => [NAN, null],
            'infinite' => [INF, null],
            'too large' => [1e12, null],
            'zero' => [0.0, '0.00'],
            'maximum' => [9999999999.99, '9999999999.99'],
            'rounded' => [10.005, '10.01'],
        ];
    }

    #[DataProvider('values')]
    public function test_goal_values_are_validated(float $value, ?string $expected): void
    {
        Analytics::for(self::VISITOR)->goal('purchase', $value);

        $this->assertSame($expected, AnalyticsEvent::query()->sole()->value);
    }

    public function test_properties_are_limited_and_cleaned(): void
    {
        $properties = [
            'plan' => 'pro',
            'count' => 3,
            'ratio' => 0.5,
            'flag' => true,
            'nothing' => null,
            'long' => str_repeat('x', 300),
            'nested' => ['a' => 1],
            str_repeat('k', 65) => 'long key',
            '' => 'empty key',
            5 => 'numeric key',
            'object' => new \stdClass,
            'infinite' => INF,
        ];

        Analytics::for(self::VISITOR)->track('signup_clicked', $properties);

        $stored = AnalyticsEvent::query()->sole()->properties;

        $this->assertSame(['plan', 'count', 'ratio', 'flag', 'nothing', 'long'], array_keys($stored));
        $this->assertSame(str_repeat('x', 255), $stored['long']);
        $this->assertTrue($stored['flag']);
        $this->assertNull($stored['nothing']);
    }

    public function test_at_most_twenty_properties_are_kept(): void
    {
        $properties = [];

        foreach (range(1, 25) as $i) {
            $properties['key'.$i] = $i;
        }

        Analytics::for(self::VISITOR)->track('signup_clicked', $properties);

        $this->assertCount(20, AnalyticsEvent::query()->sole()->properties);
    }

    public function test_nothing_is_recorded_when_tracking_is_disabled(): void
    {
        config()->set('spa-analytics.enabled', false);

        Analytics::for(self::VISITOR)->track('signup_clicked');

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_nothing_is_recorded_without_a_known_visitor(): void
    {
        Analytics::track('signup_clicked');

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_for_records_without_any_request_details(): void
    {
        Analytics::for(self::VISITOR)->goal('purchase', 10.0);

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(self::VISITOR, $event->visitor_id);
        $this->assertNull($event->path);
        $this->assertNull($event->language);
        $this->assertNull($event->ip);
        $this->assertNull($event->user_agent);
        $this->assertFalse($event->is_bot);
    }

    public function test_for_inside_a_request_does_not_borrow_the_callers_details(): void
    {
        $this->withHeaders(['User-Agent' => 'Stripe/1.0', 'Accept-Language' => 'en'])
            ->postJson('/webhook', ['visitor' => self::VISITOR])
            ->assertOk();

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(self::VISITOR, $event->visitor_id);
        $this->assertNull($event->user_agent);
        $this->assertNull($event->ip);
        $this->assertNull($event->path);
    }

    public function test_for_with_an_invalid_visitor_id_records_nothing(): void
    {
        Analytics::for('not-a-uuid')->track('signup_clicked');

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_for_with_a_null_visitor_id_records_nothing(): void
    {
        Analytics::for(null)->track('signup_clicked');

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_for_returns_a_separate_tracker(): void
    {
        $scoped = Analytics::for(self::VISITOR);

        $this->assertNotSame(app(AnalyticsTracker::class), $scoped);

        Analytics::track('signup_clicked');

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_a_failing_store_never_reaches_the_caller(): void
    {
        Log::spy();
        $this->app->bind(EventStore::class, fn () => new class implements EventStore
        {
            public function store(PageViewData|CustomEventData $data): void
            {
                throw new \RuntimeException('failed for 203.0.113.9');
            }
        });

        Analytics::for(self::VISITOR)->track('signup_clicked');

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => array_keys($context) === ['exception', 'code']
                && ! str_contains(json_encode($context), '203.0.113.9'),
        );
    }

    public function test_queue_mode_pushes_a_job(): void
    {
        Queue::fake();
        config()->set('spa-analytics.tracking.write_mode', 'queue');

        Analytics::for(self::VISITOR)->track('signup_clicked');

        Queue::assertPushed(WriteEvent::class);
    }

    public function test_defer_mode_inside_a_queue_job_writes_when_the_job_is_attempted(): void
    {
        config()->set('spa-analytics.tracking.write_mode', 'defer');

        Analytics::for(self::VISITOR)->track('signup_clicked');

        $this->assertSame(0, AnalyticsEvent::query()->count());

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('hasFailed')->andReturn(false);
        event(new JobAttempted('redis', $job));

        $this->assertSame(1, AnalyticsEvent::query()->count());
    }
}
