# Changelog

All notable changes to `laravel-spa-analytics` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-09-30

### Added

- Initial package scaffold: service provider, config file, test harness.
- Visitor cookie middleware that resolves an identity per request and binds it into the container.
- Tiered device fingerprint (stable hash plus canvas, audio, WebGL and TLS JA4 hashes) and a matcher for re-linking visitors.
- Handshake and identify endpoints: signed single-use nonce, per-session key, AES-GCM encrypted binary payload, a per-visitor rate limit plus a per-address ceiling (so visitors behind one shared address do not block each other), and CSRF protection.
- `VisitorIdentified` event dispatched after a successful identify.
- Browser collector (TypeScript, prebuilt to `resources/dist/client.js`) and the `@spaAnalytics` Blade directive.
- Page view tracking: capture middleware for GET HTML documents and Inertia visits, stored in `analytics_events` with referrer classification, UTM values, bot flag and response status.
- `defer`, `queue` and `sync` write modes with failure isolation.
- Visitor fingerprints persisted in `analytics_visitor_fingerprints` on identification.
- `EventType`, `ReferrerType` and `WriteMode` enums, `Event` and `VisitorFingerprint` models with factories, and publishable migrations.
- Sessions: page views are attached to sessions (30 minute inactivity timeout, no session cookie) stored in `analytics_sessions`, with `session_id` on events.
- Optional re-linking (`identity.relink`, off by default): a first-time fingerprint matching exactly one known visitor adopts that visitor's id, moves events and sessions, merges sessions that fall within the session timeout, re-issues the cookie and dispatches `VisitorRelinked` once per pair; identify responses report `source: "relinked"`.
- `analytics_visitor_links` routes late writes for an abandoned id to the adopted id.
- `php artisan spa-analytics:install` (publishes config, migrations and assets, offers to migrate) and a "SPA Analytics" section in `php artisan about`.
- `BotDetector` and `EventStore` contracts, bound in the register phase so an app can replace them.
- Publish tags `spa-analytics-config`, `spa-analytics-migrations` and `spa-analytics-assets`.

[Unreleased]: https://github.com/FojleRabbiRabib/laravel-spa-analytics/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/FojleRabbiRabib/laravel-spa-analytics/releases/tag/v0.1.0
