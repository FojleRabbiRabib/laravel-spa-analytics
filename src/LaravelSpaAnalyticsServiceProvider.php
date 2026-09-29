<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics;

use FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware\ResolveVisitorIdentity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelSpaAnalyticsServiceProvider extends PackageServiceProvider
{
    /**
     * Register the config file, views and routes under the full package name.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-spa-analytics')
            ->hasConfigFile('laravel-spa-analytics')
            ->hasViews('laravel-spa-analytics')
            ->hasRoute('web')
            ->hasMigrations(['create_analytics_events_table', 'create_analytics_visitor_fingerprints_table']);
    }

    /**
     * Publish assets, alias the middleware, define the rate limiter and directive, and join the web group.
     */
    public function packageBooted(): void
    {
        $this->publishes([
            __DIR__.'/../resources/dist' => public_path('vendor/laravel-spa-analytics'),
        ], 'laravel-spa-analytics-assets');

        $this->app->make(Router::class)->aliasMiddleware('spa-analytics.identity', ResolveVisitorIdentity::class);

        RateLimiter::for('spa-analytics-identity', fn (Request $request): Limit => Limit::perMinute(
            (int) config('laravel-spa-analytics.identity.rate_limit_per_minute'),
        )->by(hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'))));

        Blade::directive('spaAnalytics', fn (): string => "<?php echo view('laravel-spa-analytics::client-script')->render(); ?>");

        if (config('laravel-spa-analytics.enabled') && config('laravel-spa-analytics.identity.register_middleware')) {
            $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', ResolveVisitorIdentity::class);
        }
    }
}
