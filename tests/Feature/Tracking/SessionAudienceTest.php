<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\AudienceData;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorLink;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\DatabaseEventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\EventWriter;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\SessionMerger;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class SessionAudienceTest extends TestCase
{
    private function audience(string $country = 'BD'): AudienceData
    {
        return new AudienceData(DeviceType::Mobile, 'Android', 'Chrome', '121', $country);
    }

    private function pageView(AudienceData $audience, string $at = '2026-09-29 10:00:00', string $visitor = 'visitor-1'): PageViewData
    {
        return new PageViewData(
            type: EventType::PageView,
            visitorId: $visitor,
            path: '/pricing',
            status: 200,
            referrerHost: null,
            referrerType: ReferrerType::Direct,
            utm: ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_term' => null, 'utm_content' => null],
            language: 'en',
            ip: '203.0.113.9',
            userAgent: 'Mozilla/5.0',
            isBot: false,
            occurredAt: CarbonImmutable::parse($at),
            audience: $audience,
        );
    }

    public function test_the_migration_adds_the_audience_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('analytics_sessions', ['device_type', 'os', 'browser', 'browser_version', 'country']));
    }

    public function test_down_removes_the_audience_columns_and_keeps_sessions(): void
    {
        AnalyticsSession::factory()->create();

        (include __DIR__.'/../../../database/migrations/update_analytics_sessions_table_for_audience.php.stub')->down();

        $this->assertFalse(Schema::hasColumn('analytics_sessions', 'country'));
        $this->assertFalse(Schema::hasColumn('analytics_sessions', 'device_type'));
        $this->assertSame(1, AnalyticsSession::query()->count());
    }

    public function test_a_new_session_takes_the_audience_of_its_first_page_view(): void
    {
        app(DatabaseEventStore::class)->store($this->pageView($this->audience()));

        $session = AnalyticsSession::query()->sole();

        $this->assertSame(DeviceType::Mobile, $session->device_type);
        $this->assertSame('Android', $session->os);
        $this->assertSame('Chrome', $session->browser);
        $this->assertSame('121', $session->browser_version);
        $this->assertSame('BD', $session->country);
    }

    public function test_a_continuing_page_view_does_not_overwrite_the_audience(): void
    {
        app(DatabaseEventStore::class)->store($this->pageView($this->audience('BD')));
        app(DatabaseEventStore::class)->store($this->pageView($this->audience('DE'), '2026-09-29 10:05:00'));

        $this->assertSame('BD', AnalyticsSession::query()->sole()->country);
    }

    public function test_an_earlier_out_of_order_page_view_does_not_overwrite_the_audience(): void
    {
        app(DatabaseEventStore::class)->store($this->pageView($this->audience('BD')));
        app(DatabaseEventStore::class)->store($this->pageView($this->audience('DE'), '2026-09-29 09:55:00'));

        $this->assertSame('BD', AnalyticsSession::query()->sole()->country);
    }

    public function test_a_page_view_without_audience_leaves_the_columns_empty(): void
    {
        app(DatabaseEventStore::class)->store($this->pageView(new AudienceData));

        $session = AnalyticsSession::query()->sole();

        $this->assertNull($session->device_type);
        $this->assertNull($session->country);
    }

    public function test_the_audience_survives_a_visitor_link_rewrite(): void
    {
        VisitorLink::factory()->create(['visitor_id' => 'new', 'linked_to' => 'old']);

        app(DatabaseEventStore::class)->store($this->pageView($this->audience(), visitor: 'new'));

        $session = AnalyticsSession::query()->sole();
        $this->assertSame('old', $session->visitor_id);
        $this->assertSame('BD', $session->country);
    }

    public function test_the_audience_survives_queue_serialization(): void
    {
        config()->set('spa-analytics.tracking.write_mode', 'queue');
        config()->set('queue.default', 'sync');

        app(EventWriter::class)->write($this->pageView($this->audience()));

        $session = AnalyticsSession::query()->sole();
        $this->assertSame(DeviceType::Mobile, $session->device_type);
        $this->assertSame('BD', $session->country);
    }

    public function test_merged_sessions_keep_the_survivors_audience(): void
    {
        $survivor = AnalyticsSession::factory()->create([
            'visitor_id' => 'visitor-1',
            'started_at' => Carbon::parse('2026-09-29 09:00:00'),
            'last_seen_at' => Carbon::parse('2026-09-29 09:10:00'),
            'country' => 'BD',
            'device_type' => DeviceType::Mobile,
        ]);
        $moved = AnalyticsSession::factory()->create([
            'visitor_id' => 'visitor-1',
            'started_at' => Carbon::parse('2026-09-29 09:20:00'),
            'last_seen_at' => Carbon::parse('2026-09-29 09:30:00'),
            'country' => 'DE',
            'device_type' => DeviceType::Desktop,
        ]);

        app(SessionMerger::class)->merge('visitor-1', [$moved->id]);

        $merged = AnalyticsSession::query()->sole();
        $this->assertSame($survivor->id, $merged->id);
        $this->assertSame('BD', $merged->country);
        $this->assertSame(DeviceType::Mobile, $merged->device_type);
    }
}
