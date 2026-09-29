<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\BotDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\VisitorIdentified;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware\CapturePageView;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware\ResolveVisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Listeners\StoreVisitorFingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\DatabaseEventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\PatternBotDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\ReferrerClassifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
            ->hasRoute('web')
            ->hasMigrations([
                'create_analytics_events_table',
                'create_analytics_visitor_fingerprints_table',
                'create_analytics_sessions_table',
                'create_analytics_visitor_links_table',
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
        $this->app->bind(EventStore::class, DatabaseEventStore::class);
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
            __DIR__.'/../resources/dist' => public_path('vendor/laravel-spa-analytics'),
        ], 'spa-analytics-assets');

        $this->app->make(Router::class)->aliasMiddleware('spa-analytics.identity', ResolveVisitorIdentity::class);
        $this->app->make(Router::class)->aliasMiddleware('spa-analytics.capture', CapturePageView::class);

        AboutCommand::add('SPA Analytics', fn (): array => [
            'Tracking' => config('spa-analytics.enabled') ? 'ENABLED' : 'DISABLED',
            'Write mode' => (string) config('spa-analytics.tracking.write_mode'),
            'Session timeout' => config('spa-analytics.sessions.timeout_minutes').' minutes',
            'Re-linking' => config('spa-analytics.identity.relink') ? 'ON' : 'OFF',
        ]);

        RateLimiter::for('spa-analytics-identity', fn (Request $request): Limit => Limit::perMinute(
            (int) config('spa-analytics.identity.rate_limit_per_minute'),
        )->by(hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'))));

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
}
