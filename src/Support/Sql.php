<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Support;

final class Sql
{
    /**
     * The column as an exact, case-sensitive value. MySQL and MariaDB compare strings case- and trailing-space-insensitively
     * by default, which would merge values like /About and /about in a GROUP BY or count(distinct).
     */
    public static function exact(string $column): string
    {
        return in_array(AnalyticsDatabase::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'binary '.$column
            : $column;
    }
}
