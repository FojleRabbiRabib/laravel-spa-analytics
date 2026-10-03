<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ViewportSize;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

class ViewportCollectTest extends TestCase
{
    private const VISITOR = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:00:00');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.tracking.write_mode', 'sync');
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function collect(array $events): TestResponse
    {
        return $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0'])
            ->postJson(route('spa-analytics.collect'), ['events' => $events]);
    }

    private function viewport(mixed $width, string $path = '/pricing'): TestResponse
    {
        return $this->collect([['kind' => 'viewport', 'path' => $path, 'width' => $width]]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeSession(array $attributes = []): AnalyticsSession
    {
        return AnalyticsSession::factory()->create([
            'visitor_id' => self::VISITOR,
            'started_at' => Carbon::now()->subMinutes(2),
            'last_seen_at' => Carbon::now()->subMinute(),
            ...$attributes,
        ]);
    }

    public function test_the_width_sets_the_size_class_of_the_active_session_and_writes_no_event(): void
    {
        $session = $this->activeSession();

        $this->viewport(390)->assertNoContent();

        $this->assertSame(ViewportSize::Xs, $session->fresh()->viewport);
        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_the_session_is_not_moved_or_extended(): void
    {
        $session = $this->activeSession(['page_views' => 3, 'exit_path' => '/last']);
        $lastSeen = $session->last_seen_at->toDateTimeString();

        $this->viewport(1280)->assertNoContent();

        $fresh = $session->fresh();

        $this->assertSame(3, $fresh->page_views);
        $this->assertSame('/last', $fresh->exit_path);
        $this->assertSame($lastSeen, $fresh->last_seen_at->toDateTimeString());
    }

    public function test_the_first_value_of_a_session_is_kept(): void
    {
        $session = $this->activeSession(['viewport' => ViewportSize::Md]);

        $this->viewport(1920)->assertNoContent();

        $this->assertSame(ViewportSize::Md, $session->fresh()->viewport);
    }

    public function test_without_an_active_session_nothing_is_created_and_nothing_fails(): void
    {
        $this->viewport(1280)->assertNoContent();

        $this->activeSession(['started_at' => Carbon::now()->subHours(5), 'last_seen_at' => Carbon::now()->subHours(4)]);

        $this->viewport(1280)->assertNoContent();

        $this->assertSame(1, AnalyticsSession::query()->count());
        $this->assertNull(AnalyticsSession::query()->sole()->viewport);
    }

    public function test_widths_that_are_not_plausible_are_ignored(): void
    {
        $session = $this->activeSession();

        foreach ([0, -1, 10001, 'wide', null, [1280]] as $width) {
            $this->viewport($width)->assertNoContent();
        }

        $this->assertNull($session->fresh()->viewport);
    }

    public function test_an_excluded_path_is_skipped(): void
    {
        config()->set('spa-analytics.tracking.excluded_paths', ['reset-password/*']);
        $session = $this->activeSession();

        $this->viewport(1280, '/reset-password/abc')->assertNoContent();

        $this->assertNull($session->fresh()->viewport);
    }

    public function test_another_visitors_session_is_untouched(): void
    {
        $other = AnalyticsSession::factory()->create(['visitor_id' => 'someone-else', 'started_at' => Carbon::now()->subMinutes(2), 'last_seen_at' => Carbon::now()->subMinute()]);

        $this->viewport(1280)->assertNoContent();

        $this->assertNull($other->fresh()->viewport);
    }
}
