<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AnalyticsDatabase
{
    /**
     * The name set in spa-analytics.database.connection, or null for the application's default connection.
     */
    public static function name(): ?string
    {
        $name = config('spa-analytics.database.connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The connection the package tables live on: every query, transaction and driver check goes through it.
     */
    public static function connection(): Connection
    {
        return DB::connection(self::name());
    }

    /**
     * A query builder for a package table on the configured connection.
     */
    public static function table(string $table): Builder
    {
        return self::connection()->table($table);
    }
}
