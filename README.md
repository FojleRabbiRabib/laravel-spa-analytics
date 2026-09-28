# Laravel SPA Analytics

[![Tests](https://github.com/FojleRabbiRabib/laravel-spa-analytics/actions/workflows/run-tests.yml/badge.svg)](https://github.com/FojleRabbiRabib/laravel-spa-analytics/actions/workflows/run-tests.yml)
[![Code Style](https://github.com/FojleRabbiRabib/laravel-spa-analytics/actions/workflows/pint.yml/badge.svg)](https://github.com/FojleRabbiRabib/laravel-spa-analytics/actions/workflows/pint.yml)
[![Static Analysis](https://github.com/FojleRabbiRabib/laravel-spa-analytics/actions/workflows/phpstan.yml/badge.svg)](https://github.com/FojleRabbiRabib/laravel-spa-analytics/actions/workflows/phpstan.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/fojlerabbirabib/laravel-spa-analytics.svg)](https://packagist.org/packages/fojlerabbirabib/laravel-spa-analytics)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

A self-hosted, first-party analytics package for Laravel. The goal is feature
parity with mainstream analytics tools (GA4, Plausible, Fathom) — real-time
visitors, sessions, funnels, goals, campaign tracking, accurate
new-vs-returning visitor detection — with one constraint: **data never
leaves your own infrastructure.** No third-party SaaS, no external API
calls, no data sharing.

> **Status: pre-release.** This package is under active development. The
> capabilities below describe the target feature set; see the
> [Changelog](CHANGELOG.md) for what has actually shipped so far.

## Requirements

- PHP 8.3+
- Laravel 13.x

## Installation

```bash
composer require fojlerabbirabib/laravel-spa-analytics
```

Publish the config file:

```bash
php artisan vendor:publish --tag="laravel-spa-analytics-config"
```

## Visitor identity

- **Primary:** a durable, first-party visitor cookie set on first visit.
- **Fallback** (cookie blocked/cleared/incognito): a server-side fingerprint
  computed entirely from request headers already present on the request —
  no client-side fingerprinting library, no extra JS payload. The client's
  IP address is deliberately excluded from the fingerprint (it's stored
  separately per-event for geo/audience breakdowns) so a VPN or network
  switch doesn't break visitor continuity.
- No salt rotation, no forced anonymization window — an identifier stays
  stable so the same visitor is correctly recognized as returning,
  indefinitely.

## Planned capabilities

- **Traffic:** page views, unique visitors, sessions, new vs. returning
  visitors, bounce rate, average session duration, entry/exit pages.
- **Real-time:** current active visitor count and their current page.
- **Sources:** referrer classification, full UTM campaign tracking.
- **Audience:** device, OS, browser, viewport, language, country/region.
- **Behavior:** outbound link clicks, file downloads, 404s, scroll/
  engagement depth, custom events, conversion goals, multi-step funnels.
- **Client-side tracker:** a first-class JS client for SPA page-view
  transitions, outbound clicks, scroll depth, and custom
  `analytics.track()` events — runs alongside server-side middleware
  capture, not instead of it.

## Data retention

Raw events are kept in full by default — no forced pruning. Daily/hourly
rollup tables exist for fast dashboard queries, but they supplement raw
data rather than replacing it. A retention window is configurable via
`config/laravel-spa-analytics.php` if disk usage ever becomes a concern.

## Testing

```bash
composer test
```

## Code style & static analysis

```bash
composer format
composer analyse
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a history of changes.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.
