<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Checked on the MySQL and MariaDB jobs only, the engines that can add a timestamp update default: an old server turns
 * the first timestamp column of a table into one that changes itself on every update, which would move a session's start
 * or an event's time. Other engines have nothing to check.
 */
class TimestampColumnsRealEngineTest extends TestCase
{
    public function test_no_timestamp_column_changes_itself_on_update(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->addToAssertionCount(1);

            return;
        }

        $columns = DB::table('information_schema.columns')
            ->selectRaw('table_name as owner, column_name as name, extra as extra')
            ->where('table_schema', DB::getDatabaseName())
            ->whereIn('table_name', ['analytics_events', 'analytics_sessions', 'analytics_rollups', 'analytics_visitor_fingerprints', 'analytics_visitor_links'])
            ->whereIn('data_type', ['timestamp', 'datetime'])
            ->get();

        $this->assertNotEmpty($columns);

        foreach ($columns as $column) {
            $this->assertStringNotContainsStringIgnoringCase('on update', (string) $column->extra, $column->owner.'.'.$column->name);
        }
    }
}
