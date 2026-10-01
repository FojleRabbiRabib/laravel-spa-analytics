<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests;

use FojleRabbiRabib\LaravelSpaAnalytics\LaravelSpaAnalyticsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * The only database name real-engine mode will ever connect to.
     */
    protected const TEST_DATABASE = 'spa_analytics_test';

    /**
     * Tables dropped and recreated before every real-engine test.
     */
    private const OWN_TABLES = [
        'analytics_events',
        'analytics_rollups',
        'analytics_sessions',
        'analytics_visitor_fingerprints',
        'analytics_visitor_links',
        'cache',
        'cache_locks',
    ];

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelSpaAnalyticsServiceProvider::class,
        ];
    }

    /**
     * Real-engine mode runs the suite on MySQL, MariaDB or PostgreSQL. It is opt-in through environment variables that only
     * the CI workflow (or a developer using dedicated empty test databases) sets; otherwise the suite runs on
     * in-memory sqlite and the array cache.
     */
    protected function usesRealEngine(): bool
    {
        return getenv('SPA_ANALYTICS_TEST_CI') === '1'
            && in_array(getenv('SPA_ANALYTICS_TEST_DB'), ['mysql', 'mariadb', 'pgsql'], true);
    }

    protected function usesSharedCache(): bool
    {
        return $this->usesRealEngine() && in_array(getenv('SPA_ANALYTICS_TEST_CACHE'), ['database', 'redis'], true);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        if ($this->usesRealEngine()) {
            $this->configureRealEngine($app);
        }
    }

    /**
     * Point the app at the dedicated test database (and cache), refusing anything else.
     */
    private function configureRealEngine($app): void
    {
        $host = (string) getenv('SPA_ANALYTICS_TEST_DB_HOST');

        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new \RuntimeException('Real-engine tests only connect to a local host.');
        }

        $driver = (string) getenv('SPA_ANALYTICS_TEST_DB');
        $connection = [
            'driver' => $driver,
            'host' => $host,
            'port' => (int) getenv('SPA_ANALYTICS_TEST_DB_PORT'),
            'database' => self::TEST_DATABASE,
            'username' => (string) getenv('SPA_ANALYTICS_TEST_DB_USERNAME'),
            'password' => (string) getenv('SPA_ANALYTICS_TEST_DB_PASSWORD'),
            'prefix' => '',
        ];

        $connection += in_array($driver, ['mysql', 'mariadb'], true)
            ? ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true]
            : ['charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer'];

        $app['config']->set('database.connections.testing', $connection);

        if (! $this->usesSharedCache()) {
            return;
        }

        $store = (string) getenv('SPA_ANALYTICS_TEST_CACHE');

        $app['config']->set('cache.default', $store);
        $app['config']->set('cache.prefix', 'spat_'.bin2hex(random_bytes(4)).'_');

        if ($store === 'redis') {
            $index = (int) getenv('SPA_ANALYTICS_TEST_REDIS_DB');

            if ($index < 1 || $index > 15) {
                throw new \RuntimeException('Real-engine Redis tests need a dedicated database index between 1 and 15.');
            }

            $redisHost = (string) getenv('SPA_ANALYTICS_TEST_REDIS_HOST');

            if (! in_array($redisHost, ['127.0.0.1', 'localhost', '::1'], true)) {
                throw new \RuntimeException('Real-engine tests only connect to a local Redis host.');
            }

            $redis = [
                'host' => $redisHost,
                'port' => (int) getenv('SPA_ANALYTICS_TEST_REDIS_PORT'),
                'password' => getenv('SPA_ANALYTICS_TEST_REDIS_PASSWORD') ?: null,
                'database' => $index,
            ];

            $app['config']->set('database.redis.client', 'phpredis');
            $app['config']->set('database.redis.options.prefix', '');
            $app['config']->set('database.redis.default', $redis);
            $app['config']->set('database.redis.cache', $redis);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        if ($this->usesRealEngine()) {
            foreach (self::OWN_TABLES as $table) {
                Schema::dropIfExists($table);
            }

            if ($this->usesSharedCache() && getenv('SPA_ANALYTICS_TEST_CACHE') === 'database') {
                $this->createCacheTables();
            }
        }

        foreach (glob(__DIR__.'/../database/migrations/*.php.stub') ?: [] as $migration) {
            (include $migration)->up();
        }
    }

    protected function tearDown(): void
    {
        if ($this->usesSharedCache() && getenv('SPA_ANALYTICS_TEST_CACHE') === 'redis') {
            // Only the dedicated index guarded in configureRealEngine(); never FLUSHALL.
            $this->app['redis']->connection('cache')->flushdb();
        }

        parent::tearDown();
    }

    private function createCacheTables(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
    }
}
