<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomEventMigrationTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return [
            'visitor_id' => 'visitor',
            'occurred_at' => '2026-03-02 09:00:00',
            ...$overrides,
        ];
    }

    public function test_a_fresh_install_has_the_custom_event_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('analytics_events', ['name', 'value']));

        DB::table('analytics_events')->insert($this->row(['type' => 'goal', 'name' => 'purchase', 'value' => 49.5]));
        DB::table('analytics_events')->insert($this->row(['type' => 'custom', 'name' => 'clicked']));

        $this->assertSame(2, DB::table('analytics_events')->whereNull('path')->whereNull('status')->count());
    }

    public function test_a_fresh_install_has_every_event_column_the_package_writes(): void
    {
        $this->assertTrue(Schema::hasColumns('analytics_events', [
            'name', 'value', 'target_host', 'target_path', 'scroll_percent', 'file_extension', 'engaged_seconds',
        ]));
    }

    public function test_a_fresh_install_has_the_session_audience_and_viewport_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('analytics_sessions', [
            'device_type', 'os', 'browser', 'browser_version', 'country', 'viewport',
        ]));
    }

    public function test_the_event_type_is_a_plain_string_with_the_page_view_default(): void
    {
        DB::table('analytics_events')->insert($this->row(['path' => '/pricing', 'status' => 200]));

        $this->assertSame('page_view', DB::table('analytics_events')->value('type'));
    }
}
