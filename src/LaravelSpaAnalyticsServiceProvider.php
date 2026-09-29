<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics;

use FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware\ResolveVisitorIdentity;
use Illuminate\Contracts\Http\Kernel;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelSpaAnalyticsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-spa-analytics')
            ->hasConfigFile('laravel-spa-analytics');
    }

    public function packageBooted(): void
    {
        if (config('laravel-spa-analytics.enabled') && config('laravel-spa-analytics.identity.register_middleware')) {
            $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', ResolveVisitorIdentity::class);
        }
    }
}
