<?php

use FojleRabbiRabib\LaravelSpaAnalytics\Http\Controllers\CollectEventsController;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Controllers\IdentifyVisitorController;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Controllers\IdentityHandshakeController;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware\ResolveVisitorIdentity;
use Illuminate\Support\Facades\Route;

if (! config('spa-analytics.enabled')) {
    return;
}

Route::middleware(['web', ResolveVisitorIdentity::class, 'throttle:spa-analytics-identity'])
    ->prefix(config('spa-analytics.identity.route_prefix'))
    ->name('spa-analytics.identity.')
    ->group(function () {
        Route::post('handshake', IdentityHandshakeController::class)->name('handshake');
        Route::post('identify', IdentifyVisitorController::class)->name('identify');
    });

Route::middleware(['web', ResolveVisitorIdentity::class, 'throttle:spa-analytics-collect'])
    ->prefix(config('spa-analytics.identity.route_prefix'))
    ->name('spa-analytics.')
    ->group(function () {
        Route::post('collect', CollectEventsController::class)->name('collect');
    });
