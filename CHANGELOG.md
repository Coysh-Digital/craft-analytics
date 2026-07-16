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
- Consent state machine (Pro, off by default): our cookie → the site's CMP cookie → the `defineConsent` event, with Global Privacy Control absolute and unoverridable.
- First-party `_ca_vid` cookie: 128-bit random, HttpOnly, Secure, SameSite=Lax, signed, 13 months default and hard-capped at 24.
- `consent.js` with `craftAnalytics.consent()` and `gpcDetected`, loaded only when consent is enabled so `tracker.js` stays 1.2 KB.
- CMP adapters for Klaro, Cookiebot, CookieYes, Osano and Civic, plus IAB TCF v2.2 (purposes 1 + 8).
- Consent evidence log recording timestamp, state, method, scope and policy version — pseudonymous by construction.
- Consented raw journeys layer (opt-in, off by default) with its own retention, and optional Craft user association behind a separate setting.
- Privacy posture panel in the CP, reporting what the configuration permits in compliance language with warning badges.
- `craft-analytics/privacy/export|erase` DSAR commands, and `craft-analytics/privacy/document` generating ROPA, privacy-notice appendix and DPIA summary from the live configuration.
- GC enforces journey and consent-log retention.

- Campaign/UTM tracking with last-click (default), first-click and linear attribution; one session is always credited as exactly one session.
- Country and region from a local DB-IP Lite or GeoLite2 database, installed via `craft-analytics/geo/install`; no lookup service is ever called and no address is stored.
- Custom events with monetary values, outbound link clicks, file downloads, and scroll depth, via a separate `pro.js` so `tracker.js` stays small.
- Site-search tracking read from the URL — no JavaScript required.
- Campaigns, Locations and Events CP screens, Pro-gated at the controller with a plain Lite explanation.

### Fixed
- A visitor who withdrew consent between a hit being spooled and the drain running could have their already-spooled hits written back afterwards, silently resurrecting data they had asked to be erased. The drain now re-checks the consent log at write time.
- UTM and ad click-id parameters were kept on the recorded path, fragmenting the Pages report into a row per campaign and inflating path cardinality. They are now stripped, since they describe how a visitor arrived rather than which page they arrived at.
- The beacon sent its path and query as one string but the endpoint normalised it as if it had no query, so a beacon's dwell time could land on a different row from the pageview it belonged to.
