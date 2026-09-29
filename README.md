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

Publish the config file and the browser collector:

```bash
php artisan vendor:publish --tag="laravel-spa-analytics-config"
php artisan vendor:publish --tag="laravel-spa-analytics-assets"
```

After updating the package, re-publish the collector with
`php artisan vendor:publish --tag="laravel-spa-analytics-assets" --force`. An
old published `client.js` talking to a newer server fails silently.

Add the collector to your root Blade layout, for example just before `</body>`:

```blade
@spaAnalytics
```

The directive renders one `<script>` tag (with your CSP nonce when the app
uses one). It renders nothing when `SPA_ANALYTICS_ENABLED=false`.

## Configuration

All keys live in `config/laravel-spa-analytics.php`.

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `true` | Master switch (`SPA_ANALYTICS_ENABLED`) |
| `retention_days` | `null` | Raw event retention; `null` keeps everything |
| `identity.register_middleware` | `true` | Append the identity middleware to the `web` group |
| `identity.cookie_name` | `spa_analytics_vid` | Visitor cookie name |
| `identity.cookie_lifetime_days` | `365` | Cookie lifetime, refreshed on every response |
| `identity.tls_fingerprint_header` | `null` | Request header your proxy or CDN forwards the JA4 hash in; `null` disables the TLS signal |
| `identity.nonce_ttl_seconds` | `60` | How long a handshake nonce stays valid |
| `identity.route_prefix` | `spa-analytics` | URL prefix of the handshake and identify endpoints |
| `identity.rate_limit_per_minute` | `30` | Per-client limit on both endpoints |

If you set `identity.register_middleware` to `false`, attach the
`spa-analytics.identity` middleware alias to the routes that need a visitor
identity yourself.

## Visitor identity

- **Cookie:** a first-party visitor cookie (random UUID, encrypted, HttpOnly,
  SameSite=Lax, 365 days) is set on the first visit and stays authoritative
  whenever it is present.
- **Fingerprint:** a small first-party script collects device signals and
  sends them to the server, where they are hashed into tiers. A *stable* hash
  covers signals that browser updates do not change (screen size regardless of
  rotation, color depth, timezone, hardware, platform, languages, touch
  support). Separate hashes cover canvas rendering, audio rendering, WebGL
  strings and, when your proxy forwards it, the TLS JA4 fingerprint. Two
  fingerprints match when the stable hashes are equal and at least one of the
  other hashes is equal, so a browser major update usually keeps a visitor
  recognisable. The client IP address is not an input.
- **Transport:** the browser first requests a short-lived, single-use nonce
  and a per-session key, then sends the signals as a compressed, AES-GCM
  encrypted binary body. This makes casual inspection and tampering harder;
  the real controls are the signed nonce, strict server-side validation and
  rate limiting. It is not a secret from a determined attacker.
- **Limits:** fingerprinting is probabilistic. Two identical devices can share
  a stable hash, and a visitor whose cookie is cleared and whose volatile
  hashes all changed will look new. Later releases store fingerprints per
  event and only re-link a returning visitor when exactly one known visitor
  matches.
- **Hook:** the identify endpoint dispatches a `VisitorIdentified` event
  carrying the resolved identity and fingerprint, so your own code can react
  to it.

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
npm test
```

## Code style & static analysis

```bash
composer format
composer analyse
npm run lint
npm run types
```

## Building the collector

The browser collector is written in TypeScript under `resources/js` and shipped
prebuilt as `resources/dist/client.js`. After changing it, run `npm run build`
and commit the result; applications that install the package need no JS build
step.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a history of changes.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.
