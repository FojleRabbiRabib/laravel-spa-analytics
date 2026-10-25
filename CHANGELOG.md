# Changelog

All notable changes to `laravel-spa-analytics` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Period comparison, like GA's compare. `Stats::between(...)->compareToPrevious()` puts a report next to the period of the same length right before it (the previous period is cut where the current one has read to, so today so far is compared with yesterday up to the same time of day, to the second; a range that is over is compared with the whole previous period), and `compareTo($from, $to)` with any range, for example a year ago. The comparison answers `summary()`, `timeseries()`, `top()`, `goals()` and `funnel()` with `current`, `previous` and `changes`: a `Change` has an `absolute` difference (for a rate, the difference of the ratios) and a `percent` that is `null` when the previous value is 0. `top()` and `goals()` look up the current rows' values in the other period, so a new value has no previous row. New data objects `Change`, `SummaryComparison`, `TopRowComparison`, `GoalRowComparison`, `SeriesPointComparison` and `FunnelComparison`, and `StatsReport::topFor()` and `goalsFor()` to read the rows of given values.

- Stats reports include today so far, like GA4. The stored rollups cover completed hours; the hours after the last rolled-up one, up to now, are worked out from the raw events when you call a method, in memory and without storing anything, and added to the stored numbers in `summary()`, `timeseries()`, `top()`, `goals()` and `funnel()`. A visitor on a fresh site, before any rollup has run, shows up within minutes (the case in issue #4). `through` is then the end of what was read: now, or the end of the range if that is earlier. The live numbers are provisional until the hourly rollup runs and equal the stored ones afterwards. New `withLive()` and `withoutLive()` on the report, and config `stats.live` (default `true`; `false` returns the stored numbers by default) and `stats.live_max_hours` (default `24`: the most hours before the running hour that the raw part may include; when the rollup is further behind, none of the live part is read, so counts, users and funnels agree, and `incomplete` is true). `lastDays()` still ends at the start of today. If you published the config file, add `'live' => true` and `'live_max_hours' => 24` to its `stats` array (without them the live part is off and the cap is one hour).

### Changed

- Reports now include the running hour by default, so the numbers of `Stats::between()` that reach today change until the next rollup. Set `stats.live` to `false` (or call `withoutLive()`) for the old behaviour. `StatsReport` and `StatsService` take a new `RollupBuilder` argument (and `StatsReport` a `live` flag); the container resolves it, so only code that builds them by hand is affected. `RollupBuilder` has a new `preview()` that returns the rows of a period without storing them.

## [0.8.0] - 2026-10-25

Folded migrations, a fixed browser script and one new optional config key. After upgrading, publish the migrations again and migrate, and re-publish the script (`php artisan vendor:publish --tag=spa-analytics-assets --force`). If you published the package views, copy `src="{{ $scriptUrl }}"` into your `client-script.blade.php`. The old `update_analytics_*` migration files in your app are deleted for you. You can upgrade from any version straight to this one.

### Changed

- The migrations are folded: the five `create_*` migrations hold the final columns, and the seven `update_*` migrations of earlier versions are replaced by one `update_analytics_tables_rev_2`, which brings the tables of any version from 0.1.0 up to the current shape and does nothing on a fresh install (every change is guarded). Publish the migrations again and migrate; you can upgrade from any version straight to this one.

### Added

- `spa-analytics.database.connection` (`SPA_ANALYTICS_DB_CONNECTION`, default `null`) puts the package tables on their own database connection. The models, every raw query, the three transactions (event store, re-linking, rollups), the `binary` check for MySQL and MariaDB, and the migrations follow it; Laravel's `migrations` table stays on the default connection. Nothing changes while it is `null`. To use the key (a published config file works without it, since the default fills in), also re-publish the migrations with `--force` (the connection is named in the migration files). It moves no data: set it before the first migrate (see the README for `migrate:fresh` and transactions).
- The seven old `update_analytics_*` files that earlier versions published into your app are deleted automatically, once the new `update_analytics_tables_rev_2` is published: right before `migrate`, `migrate:fresh` or `migrate:refresh` collects the pending migrations (not with `--pretend`), and after a publish of the package tags. Only exact file names are deleted, in your `database/migrations` folder and paths outside `vendor/`; the `migrations` table is never touched, so a file restored from git is still skipped as already run. `php artisan spa-analytics:clean-migrations` runs the same cleanup on demand, and `php artisan about` shows how many old files are left.

### Fixed

- A re-published `client.js` could stay in visitors' browsers for a long time, because its URL never changed. The `@spaAnalytics` script URL now ends in `?v=` and a hash of the published file, read on every render, so a re-published script gets a new URL, and the file can be cached for as long as you like when your web server or CDN keys its cache on the query string and the file is deployed before the HTML (see the README). Without a readable published file the URL has no version, and nothing is hashed while tracking is disabled. The service is `ClientScript`, bindable in the container. If you published the package views, copy `src="{{ $scriptUrl }}"` from the new `client-script.blade.php` into your copy.
- Page views were missed when an Inertia visit was served from the prefetch cache (`<Link prefetch>`): the server ignores the `Purpose: prefetch` request, and the click sends no request, while the browser script skipped every transition on an Inertia page. The script now reports every single-page transition, Inertia pages included, and the server keeps ignoring a client page view of a path it recorded within the last five seconds, so a normal Inertia visit is still counted once. The back and forward buttons on Inertia pages, silently lost before, are counted too. Limits: a response slower than five seconds can count one visit twice, and a `replaceState` visit served from the cache is still not counted.

## [0.7.0] - 2026-10-09

Two new migrations, a new browser script, a new config key and new fields on `Summary` and `TopRow`. After upgrading, publish the migrations again and migrate, and re-publish the script (`php artisan vendor:publish --tag=spa-analytics-assets --force`). There is no history to backfill. If you published the config file, add `'disabled_dimensions' => []` to its `rollups` array (without the key nothing is disabled).

### Added

- `rollups.disabled_dimensions` config key to stop building rollup dimensions you do not need (for example `utm_term`, `utm_content`, `download`), which keeps `analytics_rollups` small and makes the rollup cheaper. `Stats::top()` throws an `InvalidArgumentException` naming the key for a disabled dimension. `total`, `goal` and `visitor_type` cannot be disabled because `Stats` reads them; they and unknown names are ignored, and the rollup command warns about them. Turning a dimension off deletes nothing: `spa-analytics:rollup --purge-disabled` deletes the stored rows of the disabled dimensions on request (permanent: history before the `retention_days` cutoff cannot be rebuilt; it cannot be combined with `--since` or `--period`). Rebuilding a bucket leaves the stored rows of a disabled dimension as they were, and after you turn a dimension on again, run `rollup --since=DATE` from the day it was turned off. If you published the config file, add `'disabled_dimensions' => []` to its `rollups` array; without the key nothing is disabled.

- Engagement time, measured like GA4. The browser script counts the seconds a visitor actively spends on a page (the tab is visible and focused and something was done within the last 15 seconds) and sends them once when the page is left, through a single-page navigation, or the tab is hidden. The server stores an `engagement` event with `engaged_seconds` (whole seconds from 1 to 1800; anything else is dropped), attached to the active session without opening or extending one, and the `events` total does not count it. Rollups sum the seconds in a new `engaged_seconds` metric on the `total` row and the `path` rows. `Stats::summary()` gets `engagedSeconds`, `avgEngagementPerUser` and `avgEngagementPerSession`, and every `top()` row gets `engagedSeconds` and `avgEngagement` (seconds divided by the row's users, which for paths is the average per user of that page). They are filled for `path` rows only; every other dimension shows `0` and `0.0`. Visitors without the script, pages left within a second and crashed tabs count as zero, so the averages read a little low.
- Migrations `update_analytics_events_table_for_engagement` (a nullable `engaged_seconds` column on `analytics_events`) and `update_analytics_rollups_table_for_engagement` (an `engaged_seconds` column on `analytics_rollups`). After upgrading, publish the migrations again and migrate, and re-publish the script; there is no history to backfill.

### Changed

- `Summary` and `TopRow` have new fields (`engagedSeconds`, `avgEngagementPerUser`, `avgEngagementPerSession` and `engagedSeconds`, `avgEngagement`), so `toArray()` has new keys. `CustomEventData` gets a trailing `engagedSeconds` argument and `EventType` an `Engagement` case; apps that bind their own `EventStore` should store `engaged_seconds` and handle the new type.
- `RollupBuilder`, `RollupRunner`, `StatsService` and `StatsReport` take a new `DimensionSettings` constructor argument. The container resolves it; only code that builds these classes by hand is affected.

## [0.6.1] - 2026-10-04

No migration, no new config and no new script. After upgrading, run `spa-analytics:rollup --since=DATE` for any period in which the scheduler was down, because buckets built by earlier versions may be partial (see the first fix below).

### Fixed

- The rollup command built the running hour and the running day, and Stats read those rows as complete. After a scheduler gap longer than `rollups.lookback_hours`, such a partial row was never rebuilt, so numbers were silently too low, and the prune could delete the raw rows the row had undercounted. Only hours and days that have ended are built now; a missed bucket has no row and shows as `incomplete` (and blocks the prune) until `spa-analytics:rollup --since=...` fills it. Buckets built by earlier versions are not rewritten: if the scheduler was down for a few hours at some point, run `rollup --since=DATE` for that period (days before the `retention_days` cutoff that already have a rollup keep their old rows). Stats now reads up to the last hour that ended before the last run.
- Funnels read the matching raw events a chunk of 500 visitors at a time instead of in one buffered result, so memory follows the chunk and no longer grows with the range and the number of events. The numbers are unchanged. The chunks are cut on the plain `visitor_id` column so its index is used.
- The per-row users of `Stats::top()` add a plain `IN` on the column before the exact (`binary`) one, so MySQL and MariaDB can use the index of `path` (paths and error paths) and of `name` (events and goals) instead of scanning every event of the range. The other dimensions have no index on their column and are unchanged in speed. The exact comparison still decides, so rows that differ only in case stay separate and the numbers are unchanged.

### Changed

- The row of today's day and the row of the running hour are no longer written. If you read `analytics_rollups` directly, read the hour rows for today, or use `Stats`.

## [0.6.0] - 2026-10-03

Two new migrations and a new browser script. After upgrading, publish the migrations again and migrate, and re-publish the script (`php artisan vendor:publish --tag=spa-analytics-assets --force`). There is no history to backfill. If you published the config file, add the new `collect.download_extensions` key (without it no file extension is listed, and only links with the `download` attribute count as downloads); if you published the views, add the new `data-downloads` attribute to your copy of `client-script.blade.php`.

### Added

- File download tracking. The browser script reports a click or middle click on a link to a file (its path ends in one of the new `collect.download_extensions`, or the link has the `download` attribute), on this site or another, as a `file_download` event instead of an outbound click. The query string and fragment never leave the browser, and the server reads the extension from the path, never from the client. New `download` (the path of a file on the site, or `host/path` elsewhere) and `file_extension` rollup dimensions, rankable with `Stats::top()` by events with exact per-row users. The script tag gets a `data-downloads` attribute.
- Migration `update_analytics_events_table_for_downloads` adds a nullable `file_extension` column to `analytics_events`. After upgrading, publish the migrations again and migrate, and re-publish the script (`vendor:publish --tag=spa-analytics-assets --force`); an old cached script reports links to files as outbound clicks. There is no history to backfill.
- `CustomEventData` gets a trailing `fileExtension` argument and `EventType` a `FileDownload` case; apps that bind their own `EventStore` should store `file_extension` and handle the new type.
- Viewport size. The browser script sends the window width once per page load; the server turns it into a size class (`xs` under 576 px, `sm` 576 to 767, `md` 768 to 991, `lg` 992 to 1199, `xl` 1200 and wider) and stores it on the visitor's active session, keeping the first value. It is not an event: no row is written, no session is opened or extended, and a viewport that arrives before the session exists (for example with a queued page view) is dropped. New `viewport` rollup dimension, rankable with `Stats::top()` by sessions with exact per-row users. When sessions merge, the survivor takes the viewport of an absorbed session if it has none.
- Migration `update_analytics_sessions_table_for_viewport` adds a nullable `viewport` column to `analytics_sessions`. After upgrading, publish the migrations again and migrate, and re-publish the script; there is no history to backfill, and visits without the script have no viewport.

## [0.5.0] - 2026-10-03

Reporting only: no migration and no new config. Run `spa-analytics:rollup --since=YYYY-MM-DD` once after upgrading to fill the new dimensions for history.

### Added

- `utm_source`, `utm_medium`, `utm_term` and `utm_content` rollup dimensions, rankable with `Stats::top()` with exact per-row users like `utm_campaign`. No migration: run `spa-analytics:rollup --since=YYYY-MM-DD` to fill history (with `retention_days` set, days before the cutoff that already have a rollup keep their old rows). `utm_term` and `utm_content` are often unique per link, so they add rollup rows the way `path` does.
- `status` and `error_path` rollup dimensions for site health: page views by response status, and page views with a status of 400 or more by status and path (`404 /missing`, `500 /checkout`), both rankable with `Stats::top()` by page views with exact per-row users. Only requests the package records are counted, so unmatched URLs show up only with a fallback route (see the 404 limit in the README). Page views reported by the browser script have no status and add no rows. No migration: run `spa-analytics:rollup --since=YYYY-MM-DD` to fill history (days before the `retention_days` cutoff that already have a rollup keep their old rows).
- `language` rollup dimension: page views and visitors by the first `Accept-Language` tag, lower-cased (`en-US` and `en-us` are one `en-us` row), rankable with `Stats::top()` by page views with exact per-row users. No migration: run `spa-analytics:rollup --since=YYYY-MM-DD` to fill history (days before the `retention_days` cutoff that already have a rollup keep their old rows).

## [0.4.0] - 2026-10-03

### Added

- The browser script reports SPA page transitions, outbound clicks, scroll depth (25, 50, 75 and 100 per cent) and exposes `window.spaAnalytics.track()` and `goal()`, through a new `POST {route_prefix}/collect` endpoint with its own throttle (`collect.rate_limit_per_minute`, `collect.rate_limit_per_ip_per_minute`). Client paths are normalised and checked against `tracking.excluded_paths`; the server skips a client page view it just recorded.
- `outbound_click` and `scroll_depth` event types with nullable `target_host`, `target_path` and `scroll_percent` columns (new migration `update_analytics_events_table_for_client_events`; publish the migrations and the assets again after upgrading), and the `outbound_host` and `scroll_depth` rollup dimensions, rankable with `Stats::top()`. The total `events` number still counts custom events and goals only.
- The `Stats` facade for reading analytics: `between()` or `lastDays()` then `summary()`, `timeseries()`, `top()` and `goals()`, plus `realtime()`. Results are readonly objects with `toArray()`. Users are distinct people over the range counted from raw events, falling back to summed daily users (`usersExact` false) for pruned days; counts cover completed hours only. New config key `stats.realtime_minutes` (default 5).
- `funnel()` on `Stats::between()` reports ordered multi-step funnels over paths, path prefixes, custom events and goals (`FunnelStep`, `Funnel` and `FunnelStepResult`, plus the `FunnelStepType` enum). Visitors are counted from raw events and pruned days are reported through `coveredFrom` and `complete`.
- `exit_path` rollup dimension (sessions by their last page). Run `spa-analytics:rollup --since=YYYY-MM-DD` again to fill it for history.

### Changed

- If you bind your own `EventStore`: `PageViewData::$status` is now nullable (page views reported by the browser have no status), `CustomEventData::$name` is now nullable, and `CustomEventData` has the trailing optional `targetHost`, `targetPath` and `scrollPercent` fields. The `EventStore` contract itself is unchanged.
- `EventType` and `RollupDimension` gained cases (`outbound_click`, `scroll_depth`, `outbound_host`), so an exhaustive `match` over them needs the new arms.
- Upgrading: publish the migrations and the assets again (`php artisan vendor:publish --tag="spa-analytics-migrations"`, then `php artisan migrate`, and `--tag="spa-analytics-assets" --force`). A cached `client.js` from 0.3.0 keeps working but sends none of the new events.

## [0.3.0] - 2026-10-02

### Added

- Hourly and daily rollups in `analytics_rollups` (total, path, referrer, UTM campaign, device, OS, browser, country, new vs returning, custom event and goal dimensions), the `AnalyticsRollup` model and the `RollupPeriod` and `RollupDimension` enums.
- `php artisan spa-analytics:rollup` (with `--since` and `--period`) to recompute rollups idempotently under a cache lock, and `php artisan spa-analytics:prune` to delete raw events and sessions older than `retention_days` once their days are rolled up.
- The hourly rollup and daily prune are registered in the Laravel scheduler; set `rollups.schedule` to `false` to schedule them yourself. New config keys `rollups.schedule` and `rollups.lookback_hours`.
- Migration `create_analytics_rollups_table`. After upgrading, run `spa-analytics:rollup --since=YYYY-MM-DD` once to build history.
- A supported engines table in the README (SQLite, MySQL 8, MariaDB 11 and PostgreSQL 16 verified; `database` and `redis` cache locks verified), and a MariaDB 11 job in the real-engine test matrix.

## [0.2.0] - 2026-10-01

### Added

- `Analytics` facade with `track()`, `goal()` and `for($visitorId)` to record custom events and goals from server code, with validated names, goal values and properties.
- `custom` and `goal` event types, `name` and `value` columns on `analytics_events`, and the `CustomEventData` data object. Custom events attach to a still-active session without changing it.
- Migration `update_analytics_events_table_for_custom_events`. Publish with `spa-analytics-migrations` (existing files are skipped) and run `php artisan migrate`; it may rewrite the events table on some engines, so run it off-peak.

- Session audience: `device_type`, `os`, `browser`, `browser_version` and `country` on `analytics_sessions`, set from a session's first page view, with the `DeviceType` enum and the `AudienceData` and `DeviceInfo` data objects.
- `DeviceDetector` and `GeoLocator` contracts with a dependency-free `PatternDeviceDetector` and a `HeaderGeoLocator` that reads the country from the header named in `audience.country_header`.
- Migration `update_analytics_sessions_table_for_audience` (nullable columns and two indexes; existing sessions stay empty).

### Changed

- `EventStore::store()` and `EventWriter::write()` accept `PageViewData|CustomEventData`. Custom `EventStore` implementations must update the signature.
- `analytics_events.type` is now a plain string column, and `path` and `status` are nullable.

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

[Unreleased]: https://github.com/FojleRabbiRabib/laravel-spa-analytics/compare/v0.8.0...HEAD
[0.8.0]: https://github.com/FojleRabbiRabib/laravel-spa-analytics/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/FojleRabbiRabib/laravel-spa-analytics/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/FojleRabbiRabib/laravel-spa-analytics/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/FojleRabbiRabib/laravel-spa-analytics/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/FojleRabbiRabib/laravel-spa-analytics/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/FojleRabbiRabib/laravel-spa-analytics/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/FojleRabbiRabib/laravel-spa-analytics/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/FojleRabbiRabib/laravel-spa-analytics/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/FojleRabbiRabib/laravel-spa-analytics/releases/tag/v0.1.0
