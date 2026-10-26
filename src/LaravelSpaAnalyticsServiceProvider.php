<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics;

use FojleRabbiRabib\LaravelSpaAnalytics\Console\CleanMigrationsCommand;
use FojleRabbiRabib\LaravelSpaAnalytics\Console\PruneCommand;
use FojleRabbiRabib\LaravelSpaAnalytics\Console\RollupCommand;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\BotDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\DeviceDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLocator;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLookup;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\VisitorIdentified;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware\CapturePageView;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware\ResolveVisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Listeners\CleanOldMigrations;
use FojleRabbiRabib\LaravelSpaAnalytics\Listeners\StoreVisitorFingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Migrations\OldPatchMigrations;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\AnalyticsTracker;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\ClientScript;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\DatabaseEventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\GeoLocatorAdapter;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\HeaderGeoLocator;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\MaxMindGeoLookup;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\PatternBotDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\PatternDeviceDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\ReferrerClassifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\Events\VendorTagPublished;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelSpaAnalyticsServiceProvider extends PackageServiceProvider
{
    /**
     * Register the config file (spa-analytics), views (laravel-spa-analytics) and routes.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-spa-analytics')
            ->hasConfigFile('spa-analytics')
            ->hasViews('laravel-spa-analytics')
            ->hasViewComposer('laravel-spa-analytics::client-script', fn (View $view) => $view->with('scriptUrl', $this->scriptUrl()))
            ->hasRoute('web')
            ->hasCommands([RollupCommand::class, PruneCommand::class, CleanMigrationsCommand::class])
            ->hasMigrations([
                'create_analytics_events_table',
                'create_analytics_visitor_fingerprints_table',
                'create_analytics_sessions_table',
                'create_analytics_visitor_links_table',
                'create_analytics_rollups_table',
                'update_analytics_tables_rev_3',
            ])
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->publishAssets()
                    ->askToRunMigrations();
            });
    }

    /**
     * Bind the tracking services in the register phase, so an app binding the same contracts in its own
     * register() replaces these defaults (a bind made at boot would override the app's).
     */
    public function packageRegistered(): void
    {
        $this->app->bind(BotDetector::class, fn (): PatternBotDetector => new PatternBotDetector(
            (array) config('spa-analytics.tracking.bot_patterns'),
        ));
        $this->app->bind(DeviceDetector::class, PatternDeviceDetector::class);
        $this->app->bind(GeoLocator::class, fn (): HeaderGeoLocator => new HeaderGeoLocator(
            config('spa-analytics.audience.country_header'),
            config('spa-analytics.audience.region_header'),
        ));
        $this->app->singleton(MaxMindGeoLookup::class, fn (): MaxMindGeoLookup => new MaxMindGeoLookup(
            (string) config('spa-analytics.audience.maxmind_database'),
        ));
        // A configured MaxMind database is the only source. A GeoLocator the app bound before GeoLookup existed is
        // wrapped; one that is also a GeoLookup is used as is.
        $this->app->bind(GeoLookup::class, function ($app): GeoLookup {
            $database = config('spa-analytics.audience.maxmind_database');

            if (is_string($database) && $database !== '') {
                return $app->make(MaxMindGeoLookup::class);
            }

            $locator = $app->make(GeoLocator::class);

            return $locator instanceof GeoLookup ? $locator : new GeoLocatorAdapter($locator);
        });
        $this->app->bind(EventStore::class, DatabaseEventStore::class);
        $this->app->singleton(AnalyticsTracker::class);
        $this->app->singleton(ClientScript::class);
        $this->app->bind(OldPatchMigrations::class, fn ($app): OldPatchMigrations => new OldPatchMigrations($app['migrator']));
        $this->app->bind(ReferrerClassifier::class, fn (): ReferrerClassifier => new ReferrerClassifier(
            (array) config('spa-analytics.tracking.search_hosts'),
            (array) config('spa-analytics.tracking.social_hosts'),
        ));
    }

    /**
     * Publish assets, alias middleware, define the rate limiter and directive, and join the web group.
     */
    public function packageBooted(): void
    {
        $this->publishes([
            __DIR__.'/../resources/dist' => public_path(dirname(ClientScript::PATH)),
        ], 'spa-analytics-assets');

        $this->app->make(Router::class)->aliasMiddleware('spa-analytics.identity', ResolveVisitorIdentity::class);
        $this->app->make(Router::class)->aliasMiddleware('spa-analytics.capture', CapturePageView::class);

        AboutCommand::add('SPA Analytics', fn (): array => [
            'Tracking' => config('spa-analytics.enabled') ? 'ENABLED' : 'DISABLED',
            'Write mode' => (string) config('spa-analytics.tracking.write_mode'),
            'Session timeout' => config('spa-analytics.sessions.timeout_minutes').' minutes',
            'Re-linking' => config('spa-analytics.identity.relink') ? 'ON' : 'OFF',
            'Routes' => $this->routesState(),
            'Old migration files' => ($old = count($this->app->make(OldPatchMigrations::class)->published())) === 0
                ? 'none'
                : "{$old} (removed at the next migrate)",
        ]);

        Event::listen(CommandStarting::class, [CleanOldMigrations::class, 'whenCommandStarts']);
        Event::listen(VendorTagPublished::class, [CleanOldMigrations::class, 'whenPublished']);

        RateLimiter::for('spa-analytics-identity', fn (Request $request): array => $this->limits($request, 'identity'));
        RateLimiter::for('spa-analytics-collect', fn (Request $request): array => $this->limits($request, 'collect'));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! config('spa-analytics.enabled') || ! config('spa-analytics.rollups.schedule')) {
                return;
            }

            $schedule->command('spa-analytics:rollup')->hourly()->withoutOverlapping();
            $schedule->command('spa-analytics:prune')->daily()->withoutOverlapping();
        });

        Blade::directive('spaAnalytics', fn (): string => "<?php echo view('laravel-spa-analytics::client-script')->render(); ?>");

        if (config('spa-analytics.enabled') && config('spa-analytics.identity.register_middleware')) {
            $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', ResolveVisitorIdentity::class);
        }

        if (config('spa-analytics.enabled')) {
            Event::listen(VisitorIdentified::class, StoreVisitorFingerprint::class);
        }

        if (config('spa-analytics.enabled') && config('spa-analytics.tracking.register_middleware')) {
            $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', CapturePageView::class);
        }
    }

    /**
     * The versioned URL of the collector script, or an empty string while tracking is off, when the view renders
     * nothing and the file is not touched.
     */
    private function scriptUrl(): string
    {
        return config('spa-analytics.enabled') && $this->hasEndpoints() ? $this->app->make(ClientScript::class)->url() : '';
    }

    /**
     * Whether the three endpoints the browser script posts to are registered, by the package or by the app.
     */
    private function hasEndpoints(): bool
    {
        return collect(['spa-analytics.identity.handshake', 'spa-analytics.identity.identify', 'spa-analytics.collect'])
            ->every(fn (string $name): bool => $this->app->make(Router::class)->has($name));
    }

    /**
     * Who serves the endpoints: the package, the app's own routes, or nobody (MISSING); OFF while tracking is off.
     */
    private function routesState(): string
    {
        return match (true) {
            ! config('spa-analytics.enabled') => 'OFF',
            ! $this->hasEndpoints() => 'MISSING',
            (bool) config('spa-analytics.identity.register_routes', true) => 'package',
            default => 'own',
        };
    }

    /**
     * The throttle limits of a package endpoint group: one per visitor cookie and a higher one per address.
     *
     * @return array<int, Limit>
     */
    private function limits(Request $request, string $group): array
    {
        $secret = (string) config('app.key');
        $visitorId = $request->cookie((string) config('spa-analytics.identity.cookie_name'));

        $limits = [
            Limit::perMinute((int) config('spa-analytics.'.$group.'.rate_limit_per_ip_per_minute'))
                ->by('ip:'.hash_hmac('sha256', (string) $request->ip(), $secret)),
        ];

        if (is_string($visitorId) && Str::isUuid($visitorId)) {
            array_unshift($limits, Limit::perMinute((int) config('spa-analytics.'.$group.'.rate_limit_per_minute'))
                ->by('visitor:'.hash_hmac('sha256', $visitorId, $secret)));
        }

        return $limits;
    }
}
