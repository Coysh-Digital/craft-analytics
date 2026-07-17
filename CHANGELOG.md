# Release Notes for Craft Analytics

## 1.0.0 - 2026-07-17

Initial release.

### Added

#### Tracking

- Server-side pageview capture on `Response::EVENT_AFTER_SEND`, after
  `fastcgi_finish_request()`, so nothing is added to time-to-first-byte.
- Three tracking modes: hybrid (default), server-side only, and client beacon
  only.
- A dependency-free client beacon, 1.8 KB gzipped, that stores nothing on the
  device. It reports the pageview as the page loads, then time on page and
  scroll depth as the visitor leaves.
- Hybrid deduplication: PHP records that it counted a view for a given visitor
  and nonce, and that visitor's beacon claims the record instead of counting
  again. Pages served by a full-page cache or the bfcache are counted correctly
  with no cache integration, and cached deliveries are left to the beacon so
  the numbers do not depend on how the cache is wired up.
- Three write drivers: `spool` (default), `queue` and `direct`, with a spool
  back-pressure guard.
- Crash-safe, idempotent drain (`craft-analytics/drain/run`, with `--watch`):
  claim-by-rename, in-memory aggregation, and batch markers committed in the
  same transaction as the writes.
- Bot filtering via CrawlerDetect plus headless, automation and
  missing-`Accept-Language` heuristics. Crawlers are kept out of the reports by
  default and counted separately on their own screen.
- Referrer channel classification (direct, search, social, referral, campaign,
  internal) with a `registerChannelRules` extension point.
- Browser, OS and device-type parsing.
- An ephemeral, cache-backed session layer, giving bounce rate, session
  duration and entry/exit pages without storing a single raw hit row.

#### Privacy

- Cookieless by default. Nothing is stored on a visitor's device unless
  consented tracking is deliberately turned on.
- A rotating visitor-hash salt, on a configurable window aligned to a quiet
  hour, destroying the previous salt as it goes.
- IP addresses are never stored: an address is hashed in memory and discarded
  within the call frame.
- Global Privacy Control support, absolute and unoverridable, plus optional
  `DNT`.
- Consent state machine (Pro, off by default): the plugin's own cookie, the
  site's CMP cookie, or the `defineConsent` event.
- First-party `_ca_vid` cookie: 128-bit random, HttpOnly, Secure,
  SameSite=Lax, signed, 13 months by default and hard-capped at 24.
- CMP adapters for Klaro, Cookiebot, CookieYes, Osano and Civic, plus IAB TCF
  v2.2 (purposes 1 and 8).
- A consent evidence log, pseudonymous by construction, recording timestamp,
  state, method, scope and policy version.
- An opt-in consented journeys layer, with its own retention and optional Craft
  user association behind a separate setting.
- A privacy posture panel reporting what the current configuration permits, in
  compliance language.
- `craft-analytics/privacy/export|erase` for data subject requests, and
  `craft-analytics/privacy/document` generating a ROPA entry, privacy-notice
  appendix and DPIA summary from the live configuration.

#### Storage

- Rollup-only storage: growth is cardinality × time, never traffic volume.
- An original pure-PHP HyperLogLog with sparse to dense representation, written
  from the published papers. Sketches merge, so a date range unions rather than
  sums.
- `redis` (native `PFADD`/`PFMERGE`), `hll` (portable sketch-on-row) and
  `exact` (membership table) unique counters, auto-detecting Redis.
- Per-(site, date, type) cardinality capping with an `__other__` overflow.
- Lossless hourly to daily compaction and retention enforcement via
  `craft-analytics/gc/run` and Craft's own GC. Both windows are configurable.
- MySQL 8+ and PostgreSQL 13+, through a single cross-database upsert helper.

#### Reports

- Dashboard with KPI tiles, a traffic chart and comparisons against the
  previous period.
- Pages, Sources, Devices, Crawlers and Real-time screens.
- Real-time shows pages per visitor and a row per visit, read entirely from the
  session cache with no database queries.
- Content reports: traffic by section, entry type and author, joined from
  Craft's own tables at query time, so they always reflect how the site is
  structured now and cost no extra storage.
- Campaign and UTM tracking (Pro) with last-click, first-click and linear
  attribution. One session is always credited as exactly one session.
- Country and region (Pro) from a local DB-IP Lite or GeoLite2 database
  installed with `craft-analytics/geo/install`. No lookup service is ever
  called and no address is stored.
- Custom events with monetary values, outbound clicks, file downloads and
  scroll depth (Pro), in a separate `pro.js` so the base tracker stays small.
- Site-search tracking read from the URL, needing no JavaScript.
- Goals (Pro): page, event, entry, session-duration and scroll-depth, each with
  an optional value. Stored in project config so they deploy, and converted
  once per session.
- Funnels (Pro): goals in order, with step-level drop-off. Order is enforced,
  so reaching step 3 before step 2 does not count as step 3.
- Emailed summary reports (Pro), sent with the site's own mailer. No
  third-party service and no tracking pixel.
- CSV and JSON export for every report, carrying exact values and stating the
  accuracy of estimated figures.
- Entry sidebar stats with a sparkline, a Views column on the entries index,
  and a dashboard widget.

#### Developer

- `craft.craftAnalytics` Twig API: `totals()`, `views()`, `popularPages()`,
  `popularEntries()` and `gpcDetected`.
- GraphQL API (Pro): `craftAnalyticsTotals` and `craftAnalyticsTopPages`,
  behind a schema component that is off by default.
- Formie and Commerce integrations (Pro), wired by class name only when those
  plugins are installed.
- `CaptureService::trackEvent()` for recording events from your own PHP.
- A cancellable `beforeTrack` event.
- Granular permissions: view, view all sites, export and manage settings,
  enforced at the controller layer and scoped per site.
- `craft-analytics/seed/run` for generating realistic development data, in dev
  mode only.
