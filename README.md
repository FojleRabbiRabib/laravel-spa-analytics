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

Publish the config file, the migrations and the browser collector, then migrate:

```bash
php artisan vendor:publish --tag="spa-analytics-config"
php artisan vendor:publish --tag="spa-analytics-migrations"
php artisan vendor:publish --tag="laravel-spa-analytics-assets"
php artisan migrate
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
| `tracking.register_middleware` | `true` | Append the page view capture middleware to the `web` group |
| `tracking.write_mode` | `defer` | `defer` (after the response is sent), `queue` (queued job) or `sync` |
| `tracking.connection` / `tracking.queue` | `null` | Queue connection and queue name used by `queue` mode |
| `tracking.excluded_paths` | `up`, `spa-analytics/*`, `reset-password/*`, `password/reset/*` | `request()->is()` patterns that are never recorded; add any other URL that carries a secret |
| `tracking.bot_patterns` | see file | Case-insensitive user agent substrings that flag a request as a bot |
| `tracking.search_hosts` / `tracking.social_hosts` | see file | Case-insensitive host substrings used to classify referrers |

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
  hashes all changed will look new. Later releases will only re-link a
  returning visitor when exactly one known visitor matches.
- **Storage:** each identified visitor's hashes are kept in
  `analytics_visitor_fingerprints` (one row per visitor, with first and last
  seen). Events reference the visitor id only.
- **Hook:** the identify endpoint dispatches a `VisitorIdentified` event
  carrying the resolved identity and fingerprint, so your own code can react
  to it.

## Page view tracking

The capture middleware joins the `web` group after the identity middleware and
records one row per page view in `analytics_events`.

| Request | Recorded |
|---|---|
| GET HTML document, any status | Yes |
| Inertia visit | Yes |
| Inertia partial reload, prefetch, redirect, non-GET, JSON or asset, excluded path | No |
| Bot user agent | Yes, with `is_bot = true` (filter with `Event::notBots()`) |
| Request without a resolved visitor identity | No |

Each row stores the visitor id, path (never the query string), response status,
referrer host and type (`direct`, `search`, `social`, `referral`; same-site
referrers count as direct), the five UTM values, first `Accept-Language` tag,
IP address, user agent (truncated to 512 characters) and time.

- **Write modes:** `defer` (default) writes after the response is sent and still
  records 4xx and 5xx responses; `queue` dispatches a `WriteEvent` job (a failed
  job keeps its payload, including the IP, in `failed_jobs`); `sync` writes
  inline. A failed write never breaks the request; only the exception class and
  code are logged.
- **404 limit:** URLs that match no route and implicit-binding 404s throw before
  web middleware runs, so only 404s and errors from matched routes are
  recorded. To capture unmatched URLs, register a fallback route. In
  `routes/web.php` it already runs in the `web` group:

```php
Route::fallback(fn () => abort(404));
```

  Elsewhere, add the group yourself: `Route::fallback(...)->middleware('web')`.
  Requests for images, scripts and other non-document resources (by `Accept`
  and `Sec-Fetch-Dest`) are never recorded, so missing assets and
  `/favicon.ico` do not count as page views.
- **Manual wiring:** if you set `tracking.register_middleware` to `false`,
  attach the `spa-analytics.capture` alias to your routes after
  `spa-analytics.identity`; without an identity nothing is recorded.

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
