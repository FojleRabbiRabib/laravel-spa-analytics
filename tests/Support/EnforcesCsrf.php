<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * Laravel skips the CSRF check while its own tests run; this makes the middleware check requests like it does in production.
 */
trait EnforcesCsrf
{
    protected function enforceCsrf(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }
}
