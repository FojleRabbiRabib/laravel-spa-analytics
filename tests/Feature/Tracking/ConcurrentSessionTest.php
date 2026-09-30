<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\DatabaseEventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\SessionTracker;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\VisitorLinkResolver;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Proves the per-visitor lock: parallel processes writing page views for one visitor must produce one session
 * with an exact page view count. Only meaningful on a real engine with a shared cache store, so it needs
 * the real-engine environment (see TestCase) and the pcntl and posix extensions.
 */
class ConcurrentSessionTest extends TestCase
{
    private const WRITERS = 8;

    private const WRITES_EACH = 20;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->usesSharedCache()) {
            $this->markTestSkipped('Needs a real database engine and a shared cache store (see TestCase).');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Needs the pcntl and posix extensions.');
        }
    }

    private function pageView(int $writer, int $write): PageViewData
    {
        return new PageViewData(
            type: EventType::PageView,
            visitorId: 'concurrent-visitor',
            path: '/writer-'.$writer,
            status: 200,
            referrerHost: null,
            referrerType: ReferrerType::Direct,
            utm: ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_term' => null, 'utm_content' => null],
            language: null,
            ip: null,
            userAgent: 'Mozilla/5.0',
            isBot: false,
            occurredAt: CarbonImmutable::parse('2026-09-29 10:00:00')->addSeconds(($writer * self::WRITES_EACH) + $write),
        );
    }

    /**
     * Fork the writers with no open connections, so each child opens its own.
     *
     * @return array<int, string> Failure marker files written by children that hit an error.
     */
    private function runWriters(): array
    {
        DB::purge();
        Cache::forgetDriver();

        if (getenv('SPA_ANALYTICS_TEST_CACHE') === 'redis') {
            foreach (['default', 'cache'] as $connection) {
                $this->app['redis']->purge($connection);
            }
        }

        $startAt = microtime(true) + 2.0;
        $markers = [];
        $pids = [];

        for ($writer = 0; $writer < self::WRITERS; $writer++) {
            $marker = sys_get_temp_dir().'/spat_failure_'.getmypid().'_'.$writer;
            $markers[] = $marker;

            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork a writer process.');
            }

            if ($pid === 0) {
                $this->writeAsChild($writer, $startAt, $marker);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        return $markers;
    }

    /**
     * Never returns: a hard kill avoids PHPUnit shutdown handlers closing the parent's sockets.
     */
    private function writeAsChild(int $writer, float $startAt, string $marker): never
    {
        try {
            $store = new DatabaseEventStore(
                app(VisitorLinkResolver::class),
                app(SessionTracker::class),
                60,
            );

            if ($startAt > microtime(true)) {
                time_sleep_until($startAt);
            }

            for ($write = 0; $write < self::WRITES_EACH; $write++) {
                $store->store($this->pageView($writer, $write));
            }
        } catch (\Throwable $e) {
            file_put_contents($marker, $e::class.' '.$e->getCode().': '.$e->getMessage());
        }

        posix_kill(getmypid(), SIGKILL);

        exit(1);
    }

    public function test_parallel_writers_for_one_visitor_share_a_single_session(): void
    {
        $markers = $this->runWriters();

        $failures = [];

        foreach ($markers as $marker) {
            if (is_file($marker)) {
                $failures[] = (string) file_get_contents($marker);
                unlink($marker);
            }
        }

        $this->assertSame([], $failures, 'A writer process failed.');

        $total = self::WRITERS * self::WRITES_EACH;

        $this->assertSame(1, AnalyticsSession::query()->count(), 'Parallel writes opened more than one session.');

        $session = AnalyticsSession::query()->sole();

        $this->assertSame($total, $session->page_views, 'Page view increments were lost.');
        $this->assertSame($total, AnalyticsEvent::query()->count());
        $this->assertSame($total, AnalyticsEvent::query()->where('session_id', $session->id)->count());
    }
}
