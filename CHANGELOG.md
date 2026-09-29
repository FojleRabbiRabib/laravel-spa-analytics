# Changelog

All notable changes to `laravel-spa-analytics` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial package scaffold: service provider, config file, test harness.
- Visitor cookie middleware that resolves an identity per request and binds it into the container.
- Tiered device fingerprint (stable hash plus canvas, audio, WebGL and TLS JA4 hashes) and a matcher for re-linking visitors.
- Handshake and identify endpoints: signed single-use nonce, per-session key, AES-GCM encrypted binary payload, rate limiting and CSRF protection.
- `VisitorIdentified` event dispatched after a successful identify.
- Browser collector (TypeScript, prebuilt to `resources/dist/client.js`) and the `@spaAnalytics` Blade directive.
- Publish tags `laravel-spa-analytics-config` and `laravel-spa-analytics-assets`.
