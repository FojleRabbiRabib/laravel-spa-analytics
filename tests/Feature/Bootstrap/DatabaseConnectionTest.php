<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\Fingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\FunnelStep;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorFingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\VisitorRelinker;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Support\Sql;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/**
 * The package tables live on a second connection and the default connection has none of them, so any query,
 * transaction or driver check that still uses the default connection fails here.
 */
class DatabaseConnectionTest extends TestCase
{
    private const TABLES = ['analytics_events', 'analytics_sessions', 'analytics_rollups', 'analytics_visitor_fingerprints', 'analytics_visitor_links'];

    private const VISITOR = '11111111-1111-4111-8111-111111111111';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.analytics', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('spa-analytics.database.connection', 'analytics');
        $app['config']->set('spa-analytics.tracking.write_mode', 'sync');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/page', fn () => '<html><body>hello</body></html>');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        foreach (self::TABLES as $table) {
            Schema::connection('testing')->dropIfExists($table);
        }

        DB::usingConnection('analytics', function (): void {
            foreach (glob(__DIR__.'/../../../database/migrations/*.php.stub') ?: [] as $migration) {
                (include $migration)->up();
            }
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function analytics(string $table): int
    {
        return DB::connection('analytics')->table($table)->count();
    }

    public function test_the_package_tables_exist_only_on_the_configured_connection(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::connection('analytics')->hasTable($table), $table);
            $this->assertFalse(Schema::connection('testing')->hasTable($table), $table);
        }
    }

    public function test_the_models_use_the_configured_connection_unless_a_subclass_sets_its_own(): void
    {
        $this->assertSame('analytics', (new AnalyticsEvent)->getConnectionName());

        $own = new class extends AnalyticsEvent
        {
            protected $connection = 'other';
        };

        $this->assertSame('other', $own->getConnectionName());
    }

    public function test_the_models_use_the_default_connection_when_none_is_configured(): void
    {
        config()->set('spa-analytics.database.connection', null);

        $this->assertNull((new AnalyticsEvent)->getConnectionName());
        $this->assertNull((new AnalyticsSession)->getConnectionName());
    }

    public function test_a_page_view_is_stored_in_a_transaction_on_the_configured_connection(): void
    {
        $names = [];
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$names): void {
            $names[] = $event->connectionName;
        });

        $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0', 'Accept' => 'text/html'])
            ->get('/page')->assertOk();

        $this->assertSame(1, $this->analytics('analytics_events'));
        $this->assertSame(1, $this->analytics('analytics_sessions'));
        $this->assertNotEmpty($names);
        $this->assertSame(['analytics'], array_values(array_unique($names)));
    }

    public function test_the_collect_endpoint_stores_events_and_the_viewport_on_the_configured_connection(): void
    {
        $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0'])
            ->postJson(route('spa-analytics.collect'), ['events' => [
                ['kind' => 'pageview', 'path' => '/docs'],
                ['kind' => 'viewport', 'path' => '/docs', 'width' => 1300],
                ['kind' => 'engagement', 'path' => '/docs', 'seconds' => 12],
                ['kind' => 'goal', 'path' => '/docs', 'name' => 'signup'],
            ]])->assertNoContent();

        $this->assertSame(3, $this->analytics('analytics_events'));
        $this->assertSame('xl', DB::connection('analytics')->table('analytics_sessions')->value('viewport'));
    }

    public function test_relinking_runs_its_transaction_on_the_configured_connection(): void
    {
        config()->set('spa-analytics.identity.relink', true);
        VisitorFingerprint::factory()->create(['visitor_id' => 'old', 'stable_hash' => 'stable', 'canvas_hash' => 'canvas', 'audio_hash' => null, 'webgl_hash' => null, 'tls_hash' => null]);
        AnalyticsEvent::factory()->create(['visitor_id' => 'new']);
        $names = [];
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$names): void {
            $names[] = $event->connectionName;
        });

        $adopted = app(VisitorRelinker::class)->relink(new VisitorIdentity('new', IdentitySource::Cookie, new Fingerprint('stable', 'canvas', null, null, null)));

        $this->assertSame('old', $adopted);
        $this->assertSame(1, AnalyticsEvent::query()->where('visitor_id', 'old')->count());
        $this->assertSame(['analytics'], array_values(array_unique($names)));
    }

    public function test_rollups_and_every_stats_query_run_on_the_configured_connection(): void
    {
        AnalyticsEvent::factory()->create(['visitor_id' => 'a', 'occurred_at' => Carbon::parse('2026-03-09 10:00:00'), 'path' => '/pricing']);
        AnalyticsEvent::factory()->create(['visitor_id' => 'b', 'occurred_at' => Carbon::parse('2026-03-09 11:00:00'), 'path' => '/pricing']);
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'a', 'name' => 'purchase', 'occurred_at' => Carbon::parse('2026-03-09 10:05:00')]);
        AnalyticsSession::factory()->create(['visitor_id' => 'a', 'started_at' => Carbon::parse('2026-03-09 10:00:00'), 'last_seen_at' => Carbon::parse('2026-03-09 10:05:00'), 'is_new_visitor' => true]);
        $names = [];
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$names): void {
            $names[] = $event->connectionName;
        });

        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-09 00:00:00')->toImmutable());

        $this->assertGreaterThan(0, AnalyticsRollup::query()->count());
        $this->assertGreaterThan(0, $this->analytics('analytics_rollups'));
        $this->assertSame(['analytics'], array_values(array_unique($names)));

        $report = Stats::between(Carbon::parse('2026-03-09'), Carbon::parse('2026-03-10'));

        $this->assertSame(2, $report->summary()->pageViews);
        $this->assertNotEmpty($report->timeseries());
        $this->assertSame('/pricing', $report->top(RollupDimension::Path)[0]->value);
        $this->assertNotEmpty($report->goals());
        $this->assertSame([2, 1], array_map(fn ($step) => $step->users, $report->funnel([FunnelStep::path('/pricing'), FunnelStep::goal('purchase')])->steps));
        $this->assertSame(0, Stats::realtime()->activeVisitors);
    }

    public function test_the_prune_command_runs_on_the_configured_connection(): void
    {
        config()->set('spa-analytics.retention_days', 3);
        AnalyticsRollup::factory()->create([
            'period' => RollupPeriod::Day,
            'bucket_start' => Carbon::parse('2026-03-05'),
            'dimension' => RollupDimension::Total,
            'value' => '',
        ]);
        AnalyticsEvent::factory()->create(['visitor_id' => 'a', 'occurred_at' => Carbon::parse('2026-03-05 10:00:00')]);
        AnalyticsSession::factory()->create(['visitor_id' => 'a', 'started_at' => Carbon::parse('2026-03-05 10:00:00'), 'last_seen_at' => Carbon::parse('2026-03-05 10:30:00')]);

        $this->artisan('spa-analytics:prune')->assertExitCode(0);

        $this->assertSame(0, $this->analytics('analytics_events'));
        $this->assertSame(0, $this->analytics('analytics_sessions'));
        $this->assertSame(1, $this->analytics('analytics_rollups'));
    }

    public function test_the_exact_comparison_follows_the_driver_of_the_configured_connection(): void
    {
        config()->set('database.connections.analytics_mysql', ['driver' => 'mysql', 'host' => 'spa-analytics-never-connects.invalid', 'port' => 1, 'database' => 'unused', 'username' => 'unused', 'password' => '']);
        config()->set('spa-analytics.database.connection', 'analytics_mysql');

        $this->assertSame('binary path', Sql::exact('path'));

        config()->set('spa-analytics.database.connection', 'analytics');

        $this->assertSame('path', Sql::exact('path'));
    }
}
