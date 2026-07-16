# Release Notes for Craft Analytics

## Unreleased

### Added
- Initial plugin scaffold: editions (Lite/Pro), settings model, config file support, dimensions table, cross-database upsert helper, rotating salt store.
- Server-side pageview capture on `Response::EVENT_AFTER_SEND`, after `fastcgi_finish_request()` — no added time-to-first-byte.
- Rotating visitor-hash salt with automatic rotation on a configurable window, aligned to a quiet hour, destroying the previous salt.
- Bot filtering via CrawlerDetect plus headless/automation and missing-`Accept-Language` heuristics.
- Global Privacy Control (and optional `DNT`) support.
- Write path with `spool` (default), `queue` and `direct` drivers, plus a spool back-pressure guard.
- Ephemeral session hot layer (cache-backed) powering session metrics without raw hit rows.
- `craft-analytics/drain/run` console command (with `--watch`), crash-safe and idempotent.
- `craft-analytics/salt/rotate` console command.
- Benchmark harness proving the TTFB and capture-cost claims.
- Rollup tables (pages, sessions, sources, devices) — the only persistent analytics storage in Lite.
- Original pure-PHP HyperLogLog with sparse→dense representation, written from the published papers; sketches merge, so date ranges union rather than sum.
- `UniqueCounterInterface` with `redis` (native `PFADD`/`PFMERGE`), `hll` (portable sketch-on-row) and `exact` (membership table) drivers, auto-detecting Redis.
- Referrer channel classification (direct/search/social/referral/campaign/internal) with a `registerChannelRules` extension point.
- Browser, OS and device-type parsing for the devices rollup.
- Per-(site, date, type) cardinality capping with an `__other__` overflow dimension.
- Hourly→daily compaction (lossless, merges sketches) and retention/GC, via `craft-analytics/gc/run` and Craft's GC.
- Client beacon: a 1.2 KB-gzipped, dependency-free, deferred tracker that stores nothing on the device and sends one request per pageview.
- Hybrid deduplication via a one-time nonce claim, so pages served by a full-page cache or the bfcache are counted without double-counting fresh ones — no cache integration required.
- Time on page, recorded from the beacon into `totalDwellMs`.
- Beacon endpoint at a configurable first-party path: anonymous, CSRF-exempt, rate-limited per visitor, bot- and GPC-filtered, always 204.
- Automatic tracker injection (`injectScript`), full-page-cache detection and a CP warning when `trackingMode` is `server`.
- CI job and test enforcing the 2 KB gzipped tracker budget (C3).
- Control panel: dashboard with KPI tiles and a traffic chart, plus Pages, Sources, Devices and Real-time screens.
- Real-time visitors, read entirely from the session cache — no database queries.
- Entry editor sidebar stats with a sparkline, and a “Views (30d)” column on the entries index.
- A dashboard widget for Craft's own dashboard.
- CSV and JSON export for every report, carrying exact values and stating the uniques accuracy.
- Granular permissions: view / view all sites / export / manage settings, enforced at the controller layer and scoped per site.
- `craft.craftAnalytics` Twig variable.
- `craft-analytics/seed/run` console command for generating realistic development data (dev mode only).
