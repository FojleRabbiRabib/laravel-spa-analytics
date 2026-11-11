<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Runs on the MySQL and MariaDB jobs only: an old server turns the first timestamp column of a table into one that
 * changes itself on every update, which would move a session's start or an event's time.
 */
class TimestampColumnsRealEngineTest extends TestCase
{
    public function test_no_timestamp_column_changes_itself_on_update(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MySQL and MariaDB add a timestamp update default.');
        }

        $extras = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->whereIn('table_name', ['analytics_events', 'analytics_sessions', 'analytics_rollups', 'analytics_visitor_fingerprints', 'analytics_visitor_links'])
            ->whereIn('data_type', ['timestamp', 'datetime'])
            ->pluck('extra', 'column_name')
            ->all();

        $this->assertNotEmpty($extras);

        foreach ($extras as $column => $extra) {
            $this->assertStringNotContainsStringIgnoringCase('on update', (string) $extra, $column);
        }
    }
}
