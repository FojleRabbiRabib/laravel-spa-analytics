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

> **Status: pre-release (0.x).** Shipped: first-party visitor identity, page
> view and session tracking, opt-in visitor re-linking, custom events and goals
> from server code, device, OS, browser and country on sessions, and hourly and
> daily rollups with a retention prune, the `Stats` query API (summary,
> timeseries, top lists, goals, funnels and real-time) and the browser client
> (SPA page views, outbound clicks, file downloads, scroll depth, engagement
> time and `window.spaAnalytics`).
> Rollups also report UTM values, response status, error paths, language and
> viewport size.
> Config keys and table layouts may change before 1.0; see the
> [Changelog](CHANGELOG.md).

## Requirements

- PHP 8.3+
- Laravel 13.x

### Supported engines

| | Verified in CI | Expected to work |
|---|---|---|
| Database | SQLite (every run), MySQL 8, MariaDB 11, PostgreSQL 16 | SQL Server is not verified; its default collation is case-insensitive and the rollup grouping has no SQL Server variant yet |
| Cache (locks) | `database` (the Laravel default), `redis` | `memcached` and DynamoDB support locks but are not tested |

Sessions and rollups take cache locks, so use a store that is shared by every
app server. `file` is only safe on a single server, and `array` and `null` give
no real locking, so keep them for tests.

## Installation

```bash
composer require fojlerabbirabib/laravel-spa-analytics
```

Run the install command. It publishes the config file, the migrations and the
browser collector, then offers to run the migrations:

```bash
php artisan spa-analytics:install
```

The command is hidden from `php artisan list` (a package-tools default) but
works as shown. To do the steps yourself:

```bash
php artisan vendor:publish --tag="spa-analytics-config"
php artisan vendor:publish --tag="spa-analytics-migrations"
php artisan vendor:publish --tag="spa-analytics-assets"
php artisan migrate
```

`php artisan about` shows the tracking state, write mode, session timeout and
re-linking under "SPA Analytics".

After updating the package, re-publish the collector with
`php artisan vendor:publish --tag="spa-analytics-assets" --force`. An
old published `client.js` talking to a newer server fails silently.

Add the collector to your root Blade layout, for example just before `</body>`:

```blade
@spaAnalytics
```

The directive renders one `<script>` tag (with your CSP nonce when the app
uses one). It renders nothing when `SPA_ANALYTICS_ENABLED=false`.

### Upgrading

Every release that adds migrations works the same way: publish again, then
migrate. Migration files you already have are left alone and only the new ones
are added.

```bash
php artisan vendor:publish --tag="spa-analytics-migrations"
php artisan migrate
```

**From 0.6.x**: engagement time. Publish the migrations again and migrate (two
new migrations add a nullable `engaged_seconds` column to `analytics_events` and
an `engaged_seconds` column to `analytics_rollups`) and re-publish the script
with `php artisan vendor:publish --tag="spa-analytics-assets" --force`; an old
cached `client.js` keeps working but sends no engagement time. The numbers start
at the upgrade, so there is nothing to backfill. `Summary` and `TopRow` have new
fields (see the Stats table), and `rollups.disabled_dimensions` is a new key in
the `rollups` config array (add `'disabled_dimensions' => []` if you published
the config; without it nothing is disabled). If you bind your own `EventStore`,
`CustomEventData` has a new trailing `engagedSeconds` and a new `engagement`
event type.

**From 0.6.0**: no migration. Only hours and days that have ended are rolled up
now, so today's day row and the running hour's row no longer exist (read the
hour rows, or use `Stats`). Buckets built by 0.6.0 and earlier can be partial
if the scheduler was down for more than `rollups.lookback_hours`; run
`php artisan spa-analytics:rollup --since=YYYY-MM-DD` for such a period (days
before the `retention_days` cutoff that already have a rollup keep their old
rows).

**From 0.5.0**: file downloads and viewport size. Publish the migrations again
and migrate (two new migrations add a nullable `file_extension` column to
`analytics_events` and a nullable `viewport` column to `analytics_sessions`),
and re-publish the script with
`php artisan vendor:publish --tag="spa-analytics-assets" --force`. An old
cached `client.js` keeps working but reports links to files as outbound clicks
and sends no viewport size.
If you published the config file, add the new `collect.download_extensions`
key, and if you published the package views, add
`data-downloads="{{ implode(',', config('spa-analytics.collect.download_extensions')) }}"`
to your copy of `client-script.blade.php`; without them the script gets no
extension list and only links with the `download` attribute count.
The rollups gain the `download`, `file_extension` and `viewport` dimensions,
which have no history to fill. If you bind your own `EventStore`, `CustomEventData` has a new
trailing `fileExtension` and the new `file_download` event type.

**From 0.4.0**: no migration. The rollups gain the `utm_source`, `utm_medium`,
`utm_term` and `utm_content` dimensions plus `status`, `error_path` and
`language`; run
`php artisan spa-analytics:rollup --since=YYYY-MM-DD` to fill them for history.
With `retention_days` set, days before the cutoff that already have a rollup
are left alone (their raw rows may be pruned), so the new dimensions start at the
cutoff.

**From 0.3.0**: the rollups gain an `exit_path` dimension; run
`php artisan spa-analytics:rollup --since=YYYY-MM-DD` again to fill it for
history (days before the `retention_days` cutoff that already have a rollup keep
their old rows). The browser client gains SPA transitions, outbound clicks, scroll depth
and `window.spaAnalytics`: publish the migrations again (one new migration adds
`target_host`, `target_path` and `scroll_percent` to `analytics_events`, all
nullable) and re-publish the script with
`php artisan vendor:publish --tag="spa-analytics-assets" --force`. An old cached
`client.js` keeps working but sends none of the new events.

**From 0.2.0** (rollups): one new migration creates `analytics_rollups`. After
migrating, run `php artisan spa-analytics:rollup --since=YYYY-MM-DD` once to
build the rollups for your existing history (see [Rollups and
retention](#rollups-and-retention)).

**From 0.1.0** (custom events and session audience): the first migration adds `name` and `value` columns and makes `type`, `path` and
`status` more permissive, which rewrites the `analytics_events` table on some
engines, so run it off-peak on a large table. If you replaced the `EventStore`
contract, its `store()` method now accepts `PageViewData|CustomEventData`.
The audience migration adds nullable columns and two indexes to
`analytics_sessions`; existing sessions keep empty values.

## Configuration

All keys live in `config/spa-analytics.php`.

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `true` | Master switch (`SPA_ANALYTICS_ENABLED`) |
| `retention_days` | `null` | Days of raw events and sessions to keep; `null`, empty, `0` or a non-number keeps everything (`SPA_ANALYTICS_RETENTION_DAYS`) |
| `collect.rate_limit_per_minute` | `120` | Per-visitor (cookie) limit on the collect endpoint |
| `collect.rate_limit_per_ip_per_minute` | `1200` | Per-address ceiling on the collect endpoint |
| `collect.download_extensions` | `pdf`, `zip`, `docx`, `xlsx`, `csv`, `mp3`, `mp4` and others | File extensions the browser script reports as downloads (see the config file for the full list); a link with the `download` attribute counts too |
| `rollups.schedule` | `true` | Register the hourly rollup and the daily prune in the Laravel scheduler |
| `rollups.lookback_hours` | `3` | Recent hours each scheduled rollup recomputes |
| `rollups.disabled_dimensions` | `[]` | Rollup dimensions to stop building (for example `utm_term`, `download`); `total`, `goal` and `visitor_type` cannot be disabled. See [Turning dimensions off](#turning-dimensions-off) |
| `stats.realtime_minutes` | `5` | Window of `Stats::realtime()` |
| `identity.register_middleware` | `true` | Append the identity middleware to the `web` group |
| `identity.cookie_name` | `spa_analytics_vid` | Visitor cookie name |
| `identity.cookie_lifetime_days` | `365` | Cookie lifetime, refreshed on every response |
| `identity.tls_fingerprint_header` | `null` | Request header your proxy or CDN forwards the JA4 hash in; `null` disables the TLS signal |
| `identity.nonce_ttl_seconds` | `60` | How long a handshake nonce stays valid |
| `identity.route_prefix` | `spa-analytics` | URL prefix of the handshake, identify and collect endpoints |
| `identity.rate_limit_per_minute` | `30` | Per-visitor (cookie) limit on both endpoints |
| `identity.rate_limit_per_ip_per_minute` | `600` | Per-address ceiling on both endpoints; high so visitors behind one shared address (carrier NAT, offices) do not block each other, and it stops clients that rotate cookies |
| `identity.relink` | `false` | Adopt a previous visitor id when a first-time fingerprint matches exactly one known visitor (see [Re-linking](#re-linking-returning-visitors)) |
| `sessions.timeout_minutes` | `30` | Inactivity gap after which the next page view starts a new session |
| `tracking.register_middleware` | `true` | Append the page view capture middleware to the `web` group |
| `tracking.write_mode` | `defer` | `defer` (after the response is sent), `queue` (queued job) or `sync` |
| `tracking.connection` / `tracking.queue` | `null` | Queue connection and queue name used by `queue` mode |
| `tracking.excluded_paths` | `up`, `spa-analytics/*`, `reset-password/*`, `password/reset/*` | `request()->is()` patterns that are never recorded, also applied to the paths the browser script reports; add any other URL that carries a secret |
| `tracking.bot_patterns` | see file | Case-insensitive user agent substrings that flag a request as a bot |
| `tracking.search_hosts` / `tracking.social_hosts` | see file | Case-insensitive host substrings used to classify referrers |
| `audience.country_header` | `null` | Request header your CDN fills with the visitor's country code (see [Audience](#audience)); `null` leaves the country empty |

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
  hashes all changed will look new.
- **Why the fingerprint needs the script:** the request headers alone (user
  agent, language, encoding, client hints, header order) are shared by many
  people on the same browser and operating system, so a hash of them collides
  far more often than one that includes rendering, hardware and screen signals.
  The only request-side signal that is hard to fake is the TLS fingerprint, which
  your proxy can forward (`identity.tls_fingerprint_header`) and which is used
  whenever it is present. Neither kind proves a device is genuine: the cookie
  is the authority and the fingerprint is only a hint for recognising a
  returning visitor.
- **Visits without the script:** a page view is always recorded from the
  request and the cookie is set on that same response, so a visitor whose
  browser blocks or never runs the script (a blocker, a failed load, leaving
  before it ran) is still counted and keeps their cookie identity. They just
  have no fingerprint, so they cannot be re-linked after clearing their
  cookie. Crawlers and monitors that skip scripts are flagged as bots.
- **Storage:** each identified visitor's hashes are kept in
  `analytics_visitor_fingerprints` (one row per visitor, with first and last
  seen). Events and sessions reference the visitor id only.
- **Hook:** the identify endpoint dispatches a `VisitorIdentified` event
  carrying the resolved identity and fingerprint, so your own code can react
  to it.

### Re-linking returning visitors

Off by default. With `identity.relink` set to `true`, a visitor who arrives
without their cookie is recognised when their **first** fingerprint matches
**exactly one** known visitor: the new id's events and sessions move to the
old id, the cookie is re-issued with the old id, and `VisitorRelinked` (new and
old id) is dispatched before `VisitorIdentified`. The identify response then
reports `source: "relinked"`. Zero matches, several matches or more than 50
candidates never re-link, so the package does not guess.

Turn it on only if you accept the trade-off: two people with identical devices
(for example two of the same phone model with the same browser) share a stable
hash and often identical rendering hashes, so the second person can be merged
into the first when their cookie is missing. Late queued writes for the
abandoned id are routed to the adopted id through `analytics_visitor_links`.

Things to know before enabling it:

- **The visitor id is not a credential.** Device signals come from the client
  and the transport is not a secret, so anyone who reproduces a device's
  signals can be handed that visitor's id. Never tie anything private to it.
- **Sessions are merged by the timeout rule.** A moved session that sits within
  `sessions.timeout_minutes` of the adopted visitor's sessions is folded into
  the earliest one (page views summed, earliest entry and attribution kept,
  latest exit used, events repointed). Sessions further apart stay separate.
- **`VisitorRelinked` fires once per pair.** A second tab or a retry that
  identifies with the abandoned id gets the adopted id back and the cookie
  re-issued, but nothing is moved or dispatched again.

## Page view tracking

The capture middleware joins the `web` group after the identity middleware and
records one row per page view in `analytics_events`.

| Request | Recorded |
|---|---|
| GET HTML document, any status | Yes |
| Inertia visit | Yes |
| Inertia partial reload, prefetch, redirect, non-GET, JSON or asset, excluded path | No |
| Bot user agent | Yes, with `is_bot = true` (filter with `AnalyticsEvent::notBots()`) |
| Request without a resolved visitor identity | No |

Each row stores the visitor id, path (never the query string), response status,
referrer host and type (`direct`, `search`, `social`, `referral`; same-site
referrers count as direct), the five UTM values, first `Accept-Language` tag,
IP address, user agent (truncated to 512 characters) and time. Page views
reported by the browser script for single-page transitions have no status (see
[Browser events](#browser-events)).

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

## Sessions

Every recorded page view is attached to a session in `analytics_sessions`; no
session cookie is used. A session continues while the visitor's next page view
arrives within `sessions.timeout_minutes` of their last one, otherwise a new
one starts.

| Field | Meaning |
|---|---|
| `started_at`, `last_seen_at` | First and latest page view; duration is their difference |
| `entry_path`, `exit_path` | First and latest path |
| `page_views` | Count; a bounce is a session with one page view (derive it at query time) |
| `referrer_host`, `referrer_type`, `utm_*` | Taken from the session's first page view only |
| `is_new_visitor` | The visitor had no earlier session |
| `is_bot` | From the first page view |
| `device_type`, `os`, `browser`, `browser_version`, `country` | From the first page view (see [Audience](#audience)) |

Session writes take a per-visitor cache lock (`Cache::lock`). In production use
a cache store shared by all app servers, such as `redis`, `database` or
`memcached`. `file` is only safe on a single server, and `array` keeps locks in
one process's memory, so it protects nothing and is meant for tests.

Filter with the scopes on the models:

```php
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;

AnalyticsSession::query()->notBots()->between($from, $to)->get(); // between() uses started_at
```

## Audience

Each session stores device type, OS, browser and country, taken from its first
page view like the referrer and UTM values. Sessions created before the audience
migration keep empty values, and merged sessions keep the earliest session's
values.

| Column | Values |
|---|---|
| `device_type` | `desktop`, `mobile`, `tablet` or `unknown` (the `DeviceType` enum); `unknown` when the user agent is missing or unrecognised |
| `os` | `Windows`, `macOS`, `Linux`, `ChromeOS`, `Android` or `iOS` |
| `browser`, `browser_version` | Family (`Chrome`, `Edge`, `Firefox`, `Safari`, `Opera`, `Samsung Internet`) and major version only |
| `country` | Two-letter ISO 3166-1 code in uppercase, or empty |

The built-in parser has no dependencies and covers the common browsers; bind
your own `DeviceDetector` for full accuracy (see [Extending](#extending)). iPads
on iPadOS 13 or later send a macOS desktop user agent and are counted as
desktop. Crawlers that imitate a browser (Googlebot's smartphone agent, for
example) are classified as that browser, so filter them with `is_bot` or
`notBots()`.

Country needs no lookup service and no IP leaves your infrastructure. If a CDN
or proxy sets a country header, name it:

```php
'audience' => ['country_header' => 'CF-IPCountry'],
```

Set it only when that header is always overwritten at your edge; otherwise any
client can send its own value. Placeholder codes (`XX`, `ZZ`, `T1`, `A1`, `A2`)
count as unknown. For a local MaxMind database or another source, bind a
`GeoLocator`.

## Custom events and goals

Record your own events from server code with the `Analytics` facade. They are
stored in `analytics_events` next to page views (`type` is `custom` or `goal`).

```php
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Analytics;

Analytics::track('signup_clicked', ['plan' => 'pro']);
Analytics::goal('purchase', 49.50, ['coupon' => 'SPRING']);
```

Inside a web request the event belongs to the current visitor and takes its
path, language, IP and user agent (and bot flag) from the request. In a queued
job, webhook or command there is no current visitor, so name one with `for()`:

```php
Analytics::for($order->visitor_id)->goal('purchase', (float) $order->total);
```

`for()` never reads the current request, so a payment provider's IP and user
agent are not attributed to the visitor; those fields stay empty and the
event is not flagged as a bot. Store the visitor id with the order when you
create it (from `app(FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity::class)->id`);
a null id records nothing.

`track()` and `goal()` only find the visitor on routes that run the identity
middleware (the `web` group, or the `spa-analytics.identity` alias). On routes
in `routes/api.php` they record nothing unless you add that alias or use `for()`.

| Rule | Behavior |
|---|---|
| Name | 1 to 128 characters from `A-Z a-z 0-9 _ . : -`; anything else is ignored |
| Goal value | Optional, 0 to 9999999999.99, stored with two decimals; an invalid value is dropped and the goal is still recorded |
| Properties | At most 20 entries; string keys of 1 to 64 characters; values must be strings (cut at 255 characters), numbers, booleans or null; anything else is dropped |
| Visitor | `for()` needs a UUID; with no known visitor, tracking disabled, or a null or invalid id, nothing is recorded |
| Failures | Recording failures are never thrown to the caller; only the exception class and code are logged |

Custom events use the same write modes as page views. They attach to the
visitor's latest session when it is still inside `sessions.timeout_minutes`
(`session_id` is empty otherwise) and never start a session, change its page
view count or extend it. Re-linking moves them with the rest of the visitor's
events. `path` and `status` are empty for events recorded through `for()`.

## Browser events

The collector script also reports what the server cannot see, to
`POST {identity.route_prefix}/collect` (same web group, CSRF and visitor
cookie as the identity endpoints, with its own throttle, see
`collect.*`). Events are batched (up to 20 per request, sent after two seconds,
on route changes and clicks at once, and when the page is hidden) with
`fetch` and `keepalive`.

| Event | What is sent | Stored as |
|---|---|---|
| SPA page view | A change of pathname through `pushState` or the back and forward buttons. Not the first load (the server records it), `replaceState`, or a change of only the query or hash. Skipped on [Inertia](https://inertiajs.com) pages (`data-page`), which the server records; the server also ignores a client page view of the same path within five seconds of one it recorded | A `page_view` with no `status`, joining the session like any other |
| Outbound click | A click or middle click on an `http(s)` link to another host: the host and path, never the query or fragment | `outbound_click` with `target_host` and `target_path` |
| File download | A click or middle click on an `http(s)` link, on this site or another, whose path ends in one of `collect.download_extensions` or that has the `download` attribute: the host (for another site) and path, never the query or fragment, so signed links stay private. It replaces the outbound click for that link | `file_download` with `target_path`, `target_host` for another site, and `file_extension` when it is one of the configured extensions (read on the server from the path) |
| Viewport size | The window width (`innerWidth`, like a CSS media query) once per page load, sent with the next batch. The server turns it into a size class, never stores the width, and keeps only the first value of a session. It is not an event: it writes no row, never opens or extends a session, and is dropped when the visitor has no session yet (for example while the page view is still queued), so a first visit can miss it | `viewport` on the session |
| Engagement time | The seconds the visitor actively spent on a page: the tab is visible and focused and something was done (scroll, key, pointer, touch) within the last 15 seconds. Sent once when the page is left, through a single-page navigation, or the tab is hidden, never on a timer, so a crashed tab loses that page's time. The server keeps whole seconds from 1 up to 1800 and drops the rest. Like the viewport it attaches to the active session and never opens or extends one | `engagement` with `engaged_seconds` |
| Scroll depth | The 25, 50, 75 and 100 per cent milestones, once each per page, only after the visitor scrolls (a page that fits the window reports nothing) | `scroll_depth` with `scroll_percent` |
| `window.spaAnalytics.track(name, properties?)` | A custom event | `custom`, like `Analytics::track()` |
| `window.spaAnalytics.goal(name, value?, properties?)` | A goal, sent at once | `goal`, like `Analytics::goal()` |

```js
window.spaAnalytics.track('clicked_cta', { plan: 'pro' });
window.spaAnalytics.goal('purchase', 49.5);
```

The script loads with `defer`, so calls made before it is ready are lost unless
the page starts a queue that the script replays in order:

```js
window.spaAnalytics = window.spaAnalytics || [];
window.spaAnalytics.push(['track', 'early_event', { a: 1 }]);
window.spaAnalytics.push(['goal', 'early_goal', 5]);
```

Names, values and properties are checked exactly as for
[`Analytics::track()`](#custom-events-and-goals). Every path sent by the browser
is normalised, never stores a query string, and is dropped when it matches
`tracking.excluded_paths`, so a single-page route such as `/reset-password/{token}`
never reaches the database. The server takes the IP address, user agent,
language and audience from the collect request itself, and an event's time is
the request time minus the age the browser reports (at most five minutes), so a
batch keeps its order.

**Privacy:** `target_path` is the path of the external page a visitor clicked
to, without the query; it can still identify a specific document or profile on
the other site. If that is more than you want to keep, clear the column after
recording (for example in a model observer) or leave outbound clicks out of
your retention plan. `target_host` and the host ranking in `Stats` do not
depend on it. The same goes for the path of a downloaded file, which can name a
document; downloads are ranked by `target_path`, so clearing it removes the
`download` rows but keeps `file_extension`.

## Extending

Four seams are contracts you can replace. Bind your own implementation in your
app's `AppServiceProvider::register()` (not `boot()`); the package's defaults
are bound in the register phase and yours wins.

| Contract | Default | Purpose |
|---|---|---|
| `FojleRabbiRabib\LaravelSpaAnalytics\Contracts\BotDetector` | `PatternBotDetector` (user agent substrings from config) | Decide whether a request is a bot |
| `FojleRabbiRabib\LaravelSpaAnalytics\Contracts\DeviceDetector` | `PatternDeviceDetector` (built-in user agent patterns) | Classify device type, OS and browser |
| `FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLocator` | `HeaderGeoLocator` (reads `audience.country_header`) | Find the visitor's country code |
| `FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore` | `DatabaseEventStore` (session plus event rows, under a lock) | Persist a page view or an event (custom event, goal, outbound click, file download or scroll depth). `PageViewData::$status` and `CustomEventData::$name` can be null |

```php
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\BotDetector;

public function register(): void
{
    $this->app->bind(BotDetector::class, MyDeviceDetectorBotDetector::class);
}
```

A throwing `DeviceDetector` or `GeoLocator` only loses its own audience values;
the page view is still recorded and the exception class and code are logged.

A custom `EventStore` receives `PageViewData` or `CustomEventData`. It may throw; the writer logs the exception class and code
and never lets the failure reach the visitor.

Only these seams are swappable. Re-linking, the session model scopes and the
visitor link lookup read and write the package's own tables directly, so a store
that writes elsewhere gets no rows in `analytics_sessions` and re-linking finds
nothing to move.

## Rollups and retention

Raw events and sessions are the source of truth and are kept in full by
default. Hourly and daily rollups in `analytics_rollups` summarise them for fast
dashboard queries; they never replace the raw rows unless you turn retention on.

`php artisan spa-analytics:rollup` recomputes the last `rollups.lookback_hours`
hours and the days they touch from the raw rows, so late queued writes and
sessions that are still open are picked up. Only hours and days that have ended
are built, never the running one, so a missed schedule can leave a bucket
missing (reported as `incomplete`, and it blocks the prune) but never a partial
one that looks complete. It replaces each bucket's rows in
one transaction, so it is safe to re-run, and it takes a cache lock so two runs
never overlap (use a shared cache store, as for sessions). With
`rollups.schedule` on (the default) the package registers it hourly and the
prune daily in the Laravel scheduler; you only need `schedule:run` (or
`schedule:work`) in cron. Turn the switch off to schedule them yourself.

Options: `--since=DATE` recomputes from a date (use it once after upgrading to
build history, and to repair writes that arrived more than the lookback late),
and `--period=hour|day|both`. Hourly rows for a long range take a while, so
backfill history with `--period=day` and let the schedule keep the hours fresh.

| Column | Meaning |
|---|---|
| `period`, `bucket_start` | `hour` or `day` and the bucket's start in `app.timezone` (use UTC; in a daylight-saving zone the repeated autumn hour collapses into one bucket) |
| `dimension`, `value` | What the row describes; the `total` dimension has an empty value |
| `page_views`, `visitors` | Page views and distinct visitors in the bucket; a day counts a visitor once, not once per hour |
| `sessions`, `bounces`, `duration_seconds` | Sessions that started in the bucket, those with a single page view, and their summed length |
| `events`, `revenue` | Custom and goal events and the summed goal value |
| `engaged_seconds` | Seconds of active time reported by the browser; only the `total` row and the `path` rows (by the page the time was spent on) carry it |

Dimensions: `total`, `path`, `exit_path`, `referrer_type`, `referrer_host`, `utm_campaign`,
`utm_source`, `utm_medium`, `utm_term`, `utm_content`, `device_type`, `os`, `browser`, `country`, `visitor_type` (`new` or `returning`),
`event` (custom event names), `goal`, `outbound_host` (target hosts of outbound
clicks), `scroll_depth` (the 25, 50, 75 and 100 milestones), `download` (files
that were downloaded: the path of a file on the site, or `host/path` for a file
elsewhere), `file_extension` (`pdf`, `zip` and the other configured extensions),
`viewport` (the size class of the
browser window, set from the first width the script reports for a session:
`xs` under 576 px, `sm` 576 to 767, `md` 768 to 991, `lg` 992 to 1199, `xl` 1200
and wider; sessions without it get no row), `status` (the
response status of page views, such as `200` or `404`) and `error_path` (page
views that got a status of 400 or more, by status and path, stored as
`404 /missing` or `500 /checkout`; unmatched URLs only count with a fallback
route, see the 404 limit above). Both rank by page views in `Stats::top()`;
page views reported by the browser script carry no status and add no row to
either, and `error_path` rows have no sessions. `language` is the first
`Accept-Language` tag of each page view, lower-cased (`en-US` and `en-us` are
one `en-us` row), ranked by page views, without session metrics.
The `events` column
and the `total` row's events count custom events and goals only; clicks,
downloads and scroll milestones have their own dimensions. Bots are never counted. Audience
dimensions come from the session. For `path` the session columns count
sessions by their entry path, and `exit_path` counts sessions by their last
page (sessions, bounces and duration only, no page views). A value that is empty (no UTM campaign, no
country) gets no row. Every bucket gets a `total` row, even with no traffic.
The UTM values are set by whoever builds the link, so `utm_term` and
`utm_content` in particular are often unique per keyword or per email and
create a row per value in every hour and day bucket, the way `path` does;
`analytics_rollups` grows with the number of distinct values.

#### Turning dimensions off

List the dimensions you do not need in `rollups.disabled_dimensions` (for
example `['utm_term', 'utm_content', 'download']`) to keep that table small.
They are no longer computed, so the rollup also gets cheaper, and
`Stats::top()` throws an `InvalidArgumentException` naming the config key for a
dimension that is off, so a missing number is never mistaken for no traffic.
`total`, `goal` and `visitor_type` cannot be turned off because `Stats` needs
them (goal completions, and new users on days whose raw rows were pruned); they
and names that are not a dimension are ignored, and the rollup command warns
about them.

Turning a dimension off deletes nothing, and rebuilding a bucket leaves its
stored rows for that dimension as they were. Those rows stay until you run
`php artisan spa-analytics:rollup --purge-disabled`, which deletes the rows of
the disabled dimensions (hour and day) in chunks and prints how many it
removed; it does no rollup and cannot be combined with `--since` or `--period`.
The delete is permanent: if you turn the dimension on again, `rollup
--since=DATE` rebuilds only from raw rows that still exist, and days before the
`retention_days` cutoff that already have a rollup are left as they are, so
history before the cutoff does not come back.

While a dimension is off its rows are not updated, and after you turn it on
again `Stats::top()` does not know that buckets from the off period are missing
or stale, so its ranking covers only the buckets that have rows. Run
`rollup --since=DATE` from the day you turned it off, before reading it again.

### Retention

Set `retention_days` and `php artisan spa-analytics:prune` deletes events and
sessions older than that many days (counted from the start of the day) in
chunks. It only deletes days that already have a daily rollup and stops at the
first day without one, telling you the `rollup --since` command to run, so
nothing is deleted before it was counted. Sessions go by their last activity,
so a session still running at the cutoff is kept. Rollups, fingerprints and
visitor links are never pruned. Before the cutoff, the rollup command only
fills days that have no daily rollup yet (their raw rows are still all there, so
this is how you unblock the prune), and leaves days that already have one alone,
so a backfill cannot overwrite them with zeros.

Things to know: lengthening `retention_days` later does not bring pruned days
back; after pruning, a returning visitor whose old sessions are gone is
recorded as new, which shifts the `visitor_type` numbers; a custom event kept
past the cutoff may point at a session that was pruned; and the day rollup is
recomputed from raw rows every hour, so its cost grows with daily traffic.

## Reading the numbers

The `Stats` facade (no global alias, import it) reads the rollups and returns
plain readonly objects with a `toArray()` for JSON. The package ships no routes
or UI: call it from your own controllers.

```php
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\FunnelStep;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;

$report = Stats::between($from, $to);   // or Stats::lastDays(7)

$report->summary();                          // Summary
$report->timeseries(RollupPeriod::Day);      // SeriesPoint[]
$report->top(RollupDimension::Path, 10);     // TopRow[]
$report->goals();                            // GoalRow[]
$report->funnel([                            // Funnel
    FunnelStep::path('/pricing'),
    FunnelStep::event('clicked_signup'),
    FunnelStep::goal('signup'),
]);

Stats::realtime();                           // Realtime
```

| Call | Returns |
|---|---|
| `summary()` | `pageViews`, `users`, `usersExact`, `newUsers`, `returningUsers`, `sessions`, `bounces`, `bounceRate`, `avgSessionDuration`, `events`, `goalCompletions`, `revenue`, `conversionRate`, `through`, `incomplete`, `engagedSeconds`, `avgEngagementPerUser`, `avgEngagementPerSession` |
| `timeseries(Hour or Day)` | One point per bucket of the range, empty buckets as zeros |
| `top(dimension, limit)` | The best values of a dimension: paths, languages, statuses and error paths by page views, events, goals, outbound hosts, scroll depth, downloads and file extensions by events, everything else by sessions. Use the entry-path sessions of `path` rows and the last page of `exit_path` rows for landing and exit pages. Throws an `InvalidArgumentException` for `total` and for a dimension in `rollups.disabled_dimensions` |
| `goals()` | Each goal with completions, revenue, users and conversion rate |
| `funnel(steps)` | Users per step with the rate from the previous and from the first step, the overall conversion, `coveredFrom`, `complete` and `through` |
| `realtime()` | Visitors with a page view in the last `stats.realtime_minutes` (default 5) and the page each of them viewed last, read from the raw events |

How the numbers are built:

- **Range:** read in `app.timezone` and snapped outward to whole hours, using
  day rollups where a whole day fits and hour rollups at the edges. Rates with
  nothing to divide by are `0.0`. `lastDays(n)` is the `n` complete days before
  today.
- **Completed hours only:** counts and users stop at `through`, the end of the
  last completed rolled-up hour, so today's chart lags by up to an hour. Use
  `realtime()` for the live window. `incomplete` is true when an hour inside the
  range has no rollup row yet (run `spa-analytics:rollup --since=...`).
- **Users** are distinct people over the whole range (like GA4 "Users"), never a
  sum of daily counts. They are counted from the raw events with
  `count(distinct ...)`, which gets slower as the range and traffic grow, and
  for `top()` and `goals()` only for the rows returned. Days whose raw rows were
  pruned fall back to the sum of daily uniques, which counts someone who came on
  two days twice; then `usersExact` is `false`.
- **Conversion rate** is the visitors who completed any goal and also viewed a
  page in the range, divided by users, at most 1. A goal's own rate counts the
  visitors who completed that goal and also viewed a page, so it never exceeds
  the overall rate; a goal row's `users` is every distinct completer, webhook
  completions included.
- **Returning users:** in `summary()` they are `users - newUsers`, where a new
  user is anyone with a first-ever session in the range. In `top(VisitorType)`
  the `returning` row counts everyone with a returning session, so a visitor
  who was new and came back inside the range appears in both rows there.
- **Engagement time** follows GA4: `avgEngagementPerUser` is the total engaged
  seconds divided by users and `avgEngagementPerSession` by sessions, and a
  `top(Path)` row's `avgEngagement` is the seconds spent on that page divided by
  the users who viewed it. Time is reported only by the browser script and only
  when a page is left or hidden, so visitors without the script, pages left
  within a second and tabs that crashed count as zero and the averages read a
  little low. Time is attributed to the page it was spent on, in the hour it was
  reported, so a row can carry engagement without page views in a bucket that
  starts after the visit. The `events` total is not touched. Only `path` rows
  carry engagement; `engagedSeconds` and `avgEngagement` are `0` and `0.0` for
  every other dimension. Each page view with the script adds about one more raw
  event row, which matters for `retention_days`.
- Bots are never counted, and path, event and campaign values that differ only
  in case stay separate rows on every engine.

Funnels take 2 to 10 steps: `FunnelStep::path()` (exact path),
`pathStartingWith()` (literal prefix, no wildcards), `event()` and `goal()`, each
with an optional label. A visitor reaches a step only after completing the steps
before it, anywhere in the range and however many days apart, and one event moves
them one step, so a step repeated twice needs two events. Within the same
second a page view counts before a goal or event, because a request's page view
is recorded after its controller has fired its goal; a goal fired during
`/thank-you` therefore follows that page view, while one fired a full second
earlier does not. Event and goal steps with the same
name are different steps, and matching is case-exact. Funnels read raw events,
which rollups cannot replace: days whose raw rows were pruned are not counted,
`coveredFrom` says where counting starts and `complete` is `false` then. Like
long-range users, a funnel scans every matching raw event in the range, and
Laravel notes that `cursor()` still lets PDO buffer the raw result, so memory
grows with the number of matching events.

## Testing

```bash
composer test
npm test
```

The identify request format is pinned by a frozen envelope,
`tests/Fixtures/identify-envelope.json`: the TypeScript test must reproduce its
bytes and the PHP test must decode it, so a change on either side fails a
suite. Regenerate it only together with a new envelope version.

By default the suite runs on in-memory sqlite and the array cache. The
GitHub Actions workflow also runs it on MySQL 8 and PostgreSQL 16, each with the
`database` and `redis` cache stores, and adds a concurrency test that forks
several processes writing page views for one visitor and asserts a single
session with an exact page view count. That mode is switched on only by
`SPA_ANALYTICS_TEST_*` variables set in CI; it refuses non-local hosts, only
connects to a database named `spa_analytics_test`, and never runs locally
unless you set those variables yourself.

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
