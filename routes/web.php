<?php

use FojleRabbiRabib\LaravelSpaAnalytics\Http\Controllers\IdentifyVisitorController;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Controllers\IdentityHandshakeController;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware\ResolveVisitorIdentity;
use Illuminate\Support\Facades\Route;

if (! config('laravel-spa-analytics.enabled')) {
    return;
}

Route::middleware(['web', ResolveVisitorIdentity::class, 'throttle:spa-analytics-identity'])
    ->prefix(config('laravel-spa-analytics.identity.route_prefix'))
    ->name('spa-analytics.identity.')
    ->group(function () {
        Route::post('handshake', IdentityHandshakeController::class)->name('handshake');
        Route::post('identify', IdentifyVisitorController::class)->name('identify');
    });
