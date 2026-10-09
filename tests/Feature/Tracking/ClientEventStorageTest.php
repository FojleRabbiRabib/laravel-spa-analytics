<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\CustomEventData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Jobs\WriteEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\EventWriter;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class ClientEventStorageTest extends TestCase
{
    private function outboundClick(): CustomEventData
    {
        return new CustomEventData(
            type: EventType::OutboundClick,
            visitorId: 'visitor-1',
            name: null,
            value: null,
            properties: [],
            path: '/pricing',
            language: 'en',
            ip: null,
            userAgent: null,
            isBot: false,
            occurredAt: CarbonImmutable::parse('2026-09-29 10:00:00'),
            targetHost: 'example.org',
            targetPath: '/docs/start',
        );
    }

    private function scrollDepth(): CustomEventData
    {
        return new CustomEventData(
            type: EventType::ScrollDepth,
            visitorId: 'visitor-1',
            name: null,
            value: null,
            properties: [],
            path: '/pricing',
            language: null,
            ip: null,
            userAgent: null,
            isBot: false,
            occurredAt: CarbonImmutable::parse('2026-09-29 10:00:00'),
            scrollPercent: 75,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writeModes(): array
    {
        return ['sync' => ['sync'], 'queue' => ['queue'], 'defer' => ['defer']];
    }

    private function write(string $mode, CustomEventData $data): void
    {
        config()->set('spa-analytics.tracking.write_mode', $mode);
        config()->set('queue.default', 'sync');

        app(EventWriter::class)->write($data);

        if ($mode === 'defer') {
            app(DeferredCallbackCollection::class)->invoke();
        }
    }

    #[DataProvider('writeModes')]
    public function test_an_outbound_click_keeps_its_target_in_every_write_mode(string $mode): void
    {
        $this->write($mode, $this->outboundClick());

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(EventType::OutboundClick, $event->type);
        $this->assertSame('example.org', $event->target_host);
        $this->assertSame('/docs/start', $event->target_path);
        $this->assertSame('/pricing', $event->path);
        $this->assertNull($event->name);
        $this->assertNull($event->scroll_percent);
    }

    #[DataProvider('writeModes')]
    public function test_a_scroll_depth_event_keeps_its_percent_in_every_write_mode(string $mode): void
    {
        $this->write($mode, $this->scrollDepth());

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(EventType::ScrollDepth, $event->type);
        $this->assertSame(75, $event->scroll_percent);
        $this->assertSame('/pricing', $event->path);
        $this->assertNull($event->target_host);
    }

    #[DataProvider('writeModes')]
    public function test_a_file_download_keeps_its_target_and_extension_in_every_write_mode(string $mode): void
    {
        $this->write($mode, new CustomEventData(
            type: EventType::FileDownload,
            visitorId: 'visitor-1',
            name: null,
            value: null,
            properties: [],
            path: '/pricing',
            language: null,
            ip: null,
            userAgent: null,
            isBot: false,
            occurredAt: CarbonImmutable::parse('2026-09-29 10:00:00'),
            targetHost: 'cdn.example.org',
            targetPath: '/a/report.zip',
            fileExtension: 'zip',
        ));

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(EventType::FileDownload, $event->type);
        $this->assertSame('cdn.example.org', $event->target_host);
        $this->assertSame('/a/report.zip', $event->target_path);
        $this->assertSame('zip', $event->file_extension);
    }

    public function test_the_downloads_migration_adds_the_column_and_drops_it_again(): void
    {
        $migration = include __DIR__.'/../../../database/migrations/update_analytics_events_table_for_downloads.php.stub';

        $migration->down();

        $this->assertFalse(Schema::hasColumn('analytics_events', 'file_extension'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('analytics_events', 'file_extension'));
    }

    #[DataProvider('writeModes')]
    public function test_engaged_seconds_survive_every_write_mode(string $mode): void
    {
        $this->write($mode, new CustomEventData(
            type: EventType::Engagement,
            visitorId: 'visitor-1',
            name: null,
            value: null,
            properties: [],
            path: '/pricing',
            language: null,
            ip: null,
            userAgent: null,
            isBot: false,
            occurredAt: CarbonImmutable::parse('2026-09-29 10:00:00'),
            engagedSeconds: 75,
        ));

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(EventType::Engagement, $event->type);
        $this->assertSame(75, $event->engaged_seconds);
    }

    public function test_the_engagement_migrations_add_their_columns_and_drop_them_again(): void
    {
        $events = include __DIR__.'/../../../database/migrations/update_analytics_events_table_for_engagement.php.stub';
        $rollups = include __DIR__.'/../../../database/migrations/update_analytics_rollups_table_for_engagement.php.stub';

        $events->down();
        $rollups->down();

        $this->assertFalse(Schema::hasColumn('analytics_events', 'engaged_seconds'));
        $this->assertFalse(Schema::hasColumn('analytics_rollups', 'engaged_seconds'));

        $events->up();
        $rollups->up();

        $this->assertTrue(Schema::hasColumn('analytics_events', 'engaged_seconds'));
        $this->assertTrue(Schema::hasColumn('analytics_rollups', 'engaged_seconds'));
    }

    public function test_the_viewport_migration_adds_the_column_and_drops_it_again(): void
    {
        $migration = include __DIR__.'/../../../database/migrations/update_analytics_sessions_table_for_viewport.php.stub';

        $migration->down();

        $this->assertFalse(Schema::hasColumn('analytics_sessions', 'viewport'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('analytics_sessions', 'viewport'));
    }

    public function test_the_new_fields_survive_to_array_and_from_array(): void
    {
        $click = CustomEventData::fromArray($this->outboundClick()->toArray());
        $scroll = CustomEventData::fromArray($this->scrollDepth()->toArray());

        $this->assertSame('example.org', $click->targetHost);
        $this->assertSame('/docs/start', $click->targetPath);
        $this->assertSame(75, $scroll->scrollPercent);
    }

    public function test_a_payload_queued_before_the_upgrade_still_rebuilds(): void
    {
        $payload = $this->outboundClick()->toArray();
        unset($payload['target_host'], $payload['target_path'], $payload['scroll_percent']);
        $payload['type'] = 'custom';
        $payload['name'] = 'clicked';

        (new WriteEvent($payload))->handle(app(EventStore::class));

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame('clicked', $event->name);
        $this->assertNull($event->target_host);
    }

    public function test_the_migration_adds_the_columns_and_drops_them_again(): void
    {
        $migration = include __DIR__.'/../../../database/migrations/update_analytics_events_table_for_client_events.php.stub';

        $migration->down();

        $this->assertFalse(Schema::hasColumn('analytics_events', 'target_host'));

        $migration->up();

        $this->assertTrue(Schema::hasColumns('analytics_events', ['target_host', 'target_path', 'scroll_percent']));
    }
}
