<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

/**
 * The guard runs before any connection is opened: these tests only compute configuration and restore it, so they
 * never reach a database whatever the machine has running.
 */
class RealEngineGuardTest extends TestCase
{
    private const VARIABLES = [
        'SPA_ANALYTICS_TEST_CI',
        'SPA_ANALYTICS_TEST_DB',
        'SPA_ANALYTICS_TEST_DB_HOST',
        'SPA_ANALYTICS_TEST_DB_PORT',
        'SPA_ANALYTICS_TEST_DB_USERNAME',
        'SPA_ANALYTICS_TEST_DB_PASSWORD',
        'SPA_ANALYTICS_TEST_CACHE',
        'SPA_ANALYTICS_TEST_REDIS_HOST',
        'SPA_ANALYTICS_TEST_REDIS_PORT',
        'SPA_ANALYTICS_TEST_REDIS_PASSWORD',
        'SPA_ANALYTICS_TEST_REDIS_DB',
    ];

    /**
     * Run the callback with exactly the given SPA_ANALYTICS_TEST_* variables, then restore the original values
     * (unsetting only the ones that were absent), so a CI environment survives for the rest of the run.
     *
     * @param  array<string, string>  $values
     */
    private function withEnvironment(array $values, callable $callback): void
    {
        $snapshot = [];
        $connection = config('database.connections.testing');

        foreach (self::VARIABLES as $name) {
            $original = getenv($name);
            $snapshot[$name] = $original === false ? null : $original;
            putenv($name);
        }

        try {
            foreach ($values as $name => $value) {
                putenv($name.'='.$value);
            }

            $callback();
        } finally {
            foreach ($snapshot as $name => $original) {
                putenv($original === null ? $name : $name.'='.$original);
            }

            $this->app['config']->set('database.connections.testing', $connection);
        }
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validValues(array $overrides): array
    {
        return [
            'SPA_ANALYTICS_TEST_CI' => '1',
            'SPA_ANALYTICS_TEST_DB' => 'mysql',
            'SPA_ANALYTICS_TEST_DB_HOST' => '127.0.0.1',
            'SPA_ANALYTICS_TEST_DB_PORT' => '3306',
            'SPA_ANALYTICS_TEST_DB_USERNAME' => 'user',
            'SPA_ANALYTICS_TEST_DB_PASSWORD' => 'secret',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function configured(array $overrides): array
    {
        $connection = [];

        $this->withEnvironment($this->validValues($overrides), function () use (&$connection): void {
            $this->defineEnvironment($this->app);
            $connection = config('database.connections.testing');
        });

        return $connection;
    }

    public function test_a_non_local_database_host_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('local host');

        $this->configured(['SPA_ANALYTICS_TEST_DB_HOST' => 'db.example.com']);
    }

    public function test_the_database_name_is_always_the_dedicated_test_database(): void
    {
        $connection = $this->configured(['SPA_ANALYTICS_TEST_DB' => 'pgsql', 'SPA_ANALYTICS_TEST_DB_PORT' => '5432']);

        $this->assertSame('spa_analytics_test', $connection['database']);
        $this->assertSame('pgsql', $connection['driver']);
        $this->assertSame('127.0.0.1', $connection['host']);
    }

    public function test_the_mysql_connection_uses_strict_utf8mb4(): void
    {
        $connection = $this->configured([]);

        $this->assertSame('mysql', $connection['driver']);
        $this->assertSame('utf8mb4', $connection['charset']);
        $this->assertTrue($connection['strict']);
    }

    public function test_redis_needs_a_dedicated_database_index(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dedicated database index');

        $this->configured([
            'SPA_ANALYTICS_TEST_CACHE' => 'redis',
            'SPA_ANALYTICS_TEST_REDIS_HOST' => '127.0.0.1',
            'SPA_ANALYTICS_TEST_REDIS_DB' => '0',
        ]);
    }

    public function test_a_non_local_redis_host_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('local Redis host');

        $this->configured([
            'SPA_ANALYTICS_TEST_CACHE' => 'redis',
            'SPA_ANALYTICS_TEST_REDIS_HOST' => 'cache.example.com',
            'SPA_ANALYTICS_TEST_REDIS_DB' => '5',
        ]);
    }

    public function test_the_mode_stays_off_without_the_ci_flag(): void
    {
        $this->withEnvironment(['SPA_ANALYTICS_TEST_DB' => 'mysql'], function (): void {
            $this->assertFalse($this->usesRealEngine());
        });
    }

    public function test_the_environment_is_restored_after_a_guard_test(): void
    {
        $before = [];

        foreach (self::VARIABLES as $name) {
            $before[$name] = getenv($name);
        }

        $this->configured([]);

        foreach (self::VARIABLES as $name) {
            $this->assertSame($before[$name], getenv($name), $name.' was not restored.');
        }
    }
}
