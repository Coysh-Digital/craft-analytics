# Release Notes for Craft Analytics

## 1.6.0 - 2026-08-03

> **What this adds to your database.** One new table,
> `craftanalytics_pagesourcesrollup`, recording how visitors reached each page.
> It fills forward only — there is nothing to backfill it from, because raw
> hits are aggregated away as they arrive — so the new card says which date
> collection started rather than implying a page had no referrers before then.
> Row growth is bounded by `dimensionCap` like every other rollup, and it is
> covered by `rollupRetentionMonths`.

### Added

- **A screen per page.** Clicking a path on the Pages report (or the
  dashboard's top-pages card) now opens that page's own report: views and
  unique visitors over time, entrances, exits, bounce rate and average time
  with period-on-period change, how people reached it, how far down they read,
  and the events and outbound clicks recorded on it. Paths that never resolved
  to a Craft entry — a template-only route, a search results page — get the
  same screen as everything else, which is the case the entry-editor link could
  never cover.
- **How people reached a given page.** No rollup could answer this: sources are
  session-grained and carry no path, so the entry path was known when a session
  closed and then discarded. The new table records it per pageview against the
  *session's* referrer, so an interior page reports how the visit started
  rather than which of your own pages preceded it.
- **Charts.** Chart.js replaces the hand-rolled SVG on the plugin's own
  screens, and there are more of them: a stacked area of traffic by channel
  over time on Sources, a device-type doughnut on Devices, a day-of-week ×
  hour-of-day heatmap on the Dashboard, and a comparison chart on Pages for
  plotting up to four paths against each other. Every chart ships a table
  carrying the same numbers for anyone who cannot see the canvas — including
  the traffic chart, whose label had promised one since it was written.

### Changed

- Chart.js 4.5.1 is vendored under `src/resources/cp/js/vendor/`, with its
  version, source and checksum recorded in `PROVENANCE.md` and its licence in
  `THIRD-PARTY-LICENSES.md`. A unit test and a CI job both re-check those
  checksums, so swapping a vendored file without recording it fails the build.
  jsvectormap, which had been vendored since 1.4.0 with no version recorded
  anywhere, is now identified (1.7.0), checksummed and listed too.
- It is loaded only on the four screens that draw a chart. Craft's entry editor
  and dashboard widgets keep their server-rendered SVG sparklines and download
  no JavaScript from this plugin at all.
- Chart colours are declared once, in `cp.css`, and read from there by both the
  stylesheet and the chart code; `#2563eb` had been written out in four places.
  The categorical palette is capped at four series plus a grey "Other" because
  that is the widest set whose colour-vision separation still measures well —
  the figures, and the method, are recorded in the stylesheet's header.

### Fixed

- Events, scroll depth, outbound clicks and site searches were silently
  discarded in the `direct` and `queue` tracking modes. Both build the same
  interaction buckets as the drain and then never passed them to the rollup
  sink, so they were collected and dropped. Only `spool`, the default, was
  recording them.
- A chart's accessible data table could not be hidden by the existing
  visually-hidden rule: a table's height is a minimum rather than a maximum, so
  a 366-row table stayed 12,000px tall, invisible but dragging the page's
  scroll height out with it. The rule now applies to a wrapper, which also
  fixes the funnel table it was already used on.
- The report screens could be scrolled sideways on a narrow viewport, by up to
  most of a screen width, with nothing to see out there. Wide content - a
  24-column heatmap, a six-column table - scrolls inside its own container as
  it always did; it was the hidden data tables reporting their full width to
  the page. Verified at 320, 375, 768 and 1400px.

## 1.5.0 - 2026-07-26

> **Before you upgrade.** Retention was only being enforced on four of the
> fourteen aggregate tables, so on most sites the Pro rollups have been
> accumulating past the period the Privacy screen states. The first garbage
> collection after this release applies `rollupRetentionMonths` (default 26) to
> all of them, and that deletion is permanent. Sites younger than the retention
> period lose nothing. If you want to keep more than 26 months, raise the
> setting **before** the next `craft-analytics/gc/run`, and take a backup if the
> data matters.

### Fixed

- Unique visitors read zero for every day older than `hourlyWindowDays` on the
  `redis` and `exact` counter drivers. Nightly compaction rewrites a day's 24
  hourly rows into one row at `hour = -1`, and the reports build the counter
  key they ask for from the stored row - so from that night on they asked for a
  key that had never been written, and Redis and the membership table both
  answered nothing. The sketch driver was never affected, because its state is
  the blob on the row compaction already merged. Compaction now folds the
  hourly counters into the daily one for every driver. Affects any site whose
  cache is Redis, which is what `auto` picks.
- Campaign traffic was reported as Direct on the Sources and Channels screens.
  The channel classifier could return Campaign but was never told whether the
  visit arrived tagged, so a `utm_`-tagged arrival with no referrer fell
  through to Direct - and the Sources screen then contradicted the Campaigns
  screen about the same visit.
- Retention was only enforced on four of the fourteen aggregate tables. Ten of
  them were kept indefinitely while the Privacy screen went on stating a
  retention period: campaigns, geo, events, scroll, search, outbound, segments,
  crawlers, goals and funnel steps. Site search terms are the sharp end of
  that, being free text a visitor typed. The table list now lives beside the
  schema, and a test reads the real schema and fails if a date-keyed table is
  missing from it.
- `craftanalytics_eventsrollup` was never compacted, so it kept 24 rows a day
  per event and path forever - the one rollup whose growth tracked traffic
  rather than cardinality.

### Security

- CSV exports no longer let a recorded value act as a spreadsheet formula.
  Paths, referrer hosts, event names and search terms are all values somebody
  else chose, and several arrive through the public beacon, so a visitor could
  request a path beginning `=` and wait for it to be exported and opened in
  Excel. JSON exports were never affected.
- The GraphQL analytics queries now check the schema's per-site scope. A token
  granted `craftAnalytics.read` could read any site on the install by passing a
  `siteId`, bypassing the site scoping applied everywhere else.
- The consent endpoint is now rate limited and rejects posts carrying a
  cross-origin `Origin` header. It is CSRF-exempt by necessity - it is posted
  to from pages that may have come from a cache - but unlike the beacon a
  forged request there writes a row into the consent evidence log or deletes
  somebody's journeys.

### Changed

- `UniqueCounterInterface` gains `compact()` and `discardCompacted()`, which is
  how the counters that live outside the rollup row now survive compaction.
  This only matters if you had implemented the interface yourself, which is not
  a documented extension point; the three shipped drivers are unaffected.

## 1.4.3 - 2026-07-22

### Fixed

- A single unusable record could stop the drain permanently, and silently. A
  visitor hash that wasn't 16 hex characters was only rejected deep inside the
  commit, by the sketch that counts unique visitors - too late to skip, and
  inside a transaction. The batch rolled back, its file stayed claimed, and
  every later run picked it up, failed at the same line, and got no further.
  Nothing surfaced it: the site kept serving, the spool kept growing, and the
  reports simply stopped advancing. The only way out was clearing the data
  cache, which is not something the error suggested and which discarded every
  open session with it. Affects sites using the sketch counter, which is the
  default wherever Redis is absent.
- Visitor hashes are now checked when they are read off the spool, where a
  counter for discarded lines already existed, and anything unusable that
  reaches the rollup writer some other way is dropped with a warning rather
  than thrown. Losing one visitor from one bucket's estimate is a rounding
  error; losing every write after it is an outage.
- A corrupt unique-visitor sketch on a rollup row wedged the drain the same
  way, and worse - being in the database, clearing the cache did not fix it. A
  sketch that cannot be read is now rebuilt from scratch for that row.
- Changing `hllPrecision` on a site with existing data broke every unique
  figure in the reports and stopped the nightly compaction, because sketches
  written at the old precision cannot be merged with new ones. Unreadable
  sketches are now skipped when answering a range, and rebuilt as each row is
  next written.
- A batch that fails is no longer retried forever. It gets three attempts, so
  a deadlock or a dropped connection costs nothing, and is then moved aside to
  a `.failed` file so the batches behind it can commit. Nothing is deleted:
  `craft-analytics/drain/retry` puts quarantined batches back once the cause
  is fixed, and a retried batch keeps its identity so it cannot be double
  counted.
- `drain/run` now reports failed and quarantined batches and exits non-zero
  when there are any, so a cron job says something instead of failing quietly.

## 1.4.2 - 2026-07-21

### Fixed

- The world map on the Locations report drew nothing on sites behind
  Cloudflare Rocket Loader, while the country and region tables below it were
  fine. Rocket Loader rewrites inline scripts and defers them, and on an
  authenticated control panel it frequently never runs them at all - so the
  map library and its data loaded over the network, the code that draws the
  map never executed, and the card sat empty with nothing in the console to
  say why. The map is now drawn by a published script that reads its figures
  from a JSON block, neither of which Rocket Loader touches. Nothing needs
  turning off at the CDN, and the map looks and behaves exactly as before.
- If the block holding the map's figures ever goes missing, the report now
  says so rather than drawing an empty grey world that looks deliberate.

## 1.4.1 - 2026-07-21

### Fixed

- Clicking a section or an author on the Content report returned a 400,
  "Request missing required param", instead of the drill-down. Both actions
  read the id from the request, but Craft hands a matched route's tokens
  straight to the action and never puts them in the request - so the id was
  never there to find. They take it as an argument now, the way the goal and
  funnel editors already did.
- The world map on the Locations report drew every country that had traffic in
  black rather than shading it by session volume. The map library's `scale` is
  a lookup table rather than a gradient, so it was being asked for the colour
  of "4,213 sessions", finding nothing, and leaving the country with no fill
  at all. Sessions are now bucketed into six shades.
- If the map's script cannot be loaded at all, the Locations report now says
  so on the screen and in the console, instead of leaving an empty card with
  no explanation. The country and region tables are unaffected either way.

## 1.4.0 - 2026-07-21

### Added

- **Segments (Pro).** A site's own module can now declare what to break its
  traffic down by - the plan somebody is on, whether they were signed in, the
  role they hold - and a new Segments report shows sessions, views, bounce
  rate and average duration for each. Values can be resolved server-side or,
  for pages served from a cache, set from the browser. Nothing about an
  individual is stored: a segment is a counter, capped and aggregated like
  every other dimension, and a visit in a segment costs one row a day however
  many pages it covered. The report and its nav item appear only once
  something is declared.
- **Your own IDs for consented visitors (Pro).** A module can supply its own
  identifier - a customer or member number - for a visitor who has
  affirmatively consented, so the journeys layer and subject access requests
  use the identifier the rest of the business already uses. Every existing
  gate still applies first, and a browser privacy signal still cannot be
  overridden. The privacy panel raises a warning when a handler is attached,
  because this makes the consented layer directly identifiable rather than
  pseudonymous.
- A new [Extending](https://coysh.digital/plugins/craft-analytics/docs/developers/extending)
  page in the docs covering both, plus the three extension events that had
  existed since 1.0 without ever being written down.

### Changed

- The beacon endpoint now refuses events, outbound clicks and downloads when
  `enableEvents` is off, rather than only on Lite. The setting now means the
  same thing at the endpoint as it does in the tracker.
- `craftanalytics_journeys.visitorId` and `craftanalytics_consentlog.visitorId`
  widen from `char(32)` to `varchar(64)` to hold a site-supplied identifier.
  Existing values are unaffected.

## 1.3.0 - 2026-07-21

### Added

- The Locations report now includes a world map, shaded by session volume,
  alongside the existing Countries table.
- Author names on Content > Authors now link through to that author's
  individual entries, ranked by views - the same drill-down the Sections tab
  already has.

### Fixed

- The `__other__` row on the Pages report is no longer occasionally
  hyperlinked to an unrelated real page. It picked up its link target from
  whichever real page's rollup row it happened to borrow an element ID from
  once folded past the daily cap - it now always renders as plain text,
  matching what it represents (not a real page).

## 1.2.3 - 2026-07-20

### Added

- The Pages report can now be filtered to only show paths containing a given
  substring, or to exclude paths containing one - useful for pulling `?ad=`
  variants out of the list, or narrowing to one section of the site. The
  filter rides along when you switch date range or site.
- The `__other__` row on the Pages report now explains itself on hover: it is
  the daily cardinality cap's catch-all, not a real page, and the tooltip
  states the current cap.
- The Locations report now notes that known crawlers and bots are filtered
  out before location data is recorded, so the figures only reflect human
  visitors.

## 1.2.2 - 2026-07-19

### Fixed

- Query parameters whose separators had been HTML-entity-encoded (`&amp;`,
  sometimes escaped more than once) are now decoded before the strip lists run,
  so a campaign tag arriving as `amp;utm_source` is recognised and removed
  instead of surviving as junk that fragments the path.
- The Real-time tables now keep their horizontal scroll inside their own card at
  every width. A long unbroken URL in a cell could previously push the whole
  screen sideways on desktop, because the scroll rule was scoped to mobile only.

## 1.2.1 - 2026-07-17

### Added

- A **What gets tracked** section on the settings screen, with switches for
  geography, campaigns and attribution model, events and their outbound,
  download and scroll sub-options, and site search with its path and parameter.
  These already existed in `config/craft-analytics.php` but had no control in
  the CP, so geography in particular looked as though it could not be turned on.

## 1.2.0 - 2026-07-17

### Added

- A `stripQueryString` setting, with a switch on the settings screen, that
  records the clean path only and drops everything after the `?`. Campaign tags
  are still read for attribution first, so turning it on collapses every `?ad=`,
  `?cid=` and similar variant of a page onto one row without losing your Sources
  report.

### Changed

- The list of query parameters stripped from tracked paths now covers the newer
  ad-network and analytics parameters (`gad_source`, `gad_campaignid`, `gclsrc`,
  `srsltid`, `_gl`, `_ga`, `_gac`, `_gid`) and Craft's preview `token`, so these
  no longer fragment a page into a row each in the Pages report.

## 1.1.0 - 2026-07-17

### Added

- The dashboard now leads with a live banner showing how many visitors are on
  the site right now, refreshed every 15 seconds and linking through to the
  Real-time screen. It reads the session cache only, so it costs no database
  query.
- A new **Analytics real-time** dashboard widget puts the live visitor count and
  the pages people are on onto Craft's own dashboard, refreshed on the same 15
  second poll.
- The **Analytics** overview widget can now show any of seven figures - views,
  visitors, sessions, bounce rate, pages per session, average session and
  average time on page - chosen in its settings. It still ships showing views,
  visitors and bounce rate, so existing widgets look the same after upgrading.

## 1.0.1 - 2026-07-17

### Added

- The dashboard now leads with seven figures rather than four, adding pages per
  session, average session duration and average time on page.
- Top pages gained an average time column.
- The dashboard's lists are now a two-up grid, and Sections, Goals, Campaigns
  and Locations have joined Channels and Devices there.
- On Lite, the Pro cards keep their place and say what they would show, instead
  of disappearing. The queries behind them do not run.

### Fixed

- The Locations card read the wrong key off the countries query, so its labels
  would have rendered blank.
- The KPI strip drew its dividers as a gap over a background, which painted the
  empty cells beside a wrapped row grey. The dividers are now the tiles' own
  borders.
- The Crawlers screen styled a badge class that does not exist.
- The Goals screen offered an Export CSV button on Lite, where the export
  action refuses, so the button returned a 403. The layout's guard tested
  whether the variable was defined, which is true for a variable set to null.
- The Pro gate read "Goals is a Pro feature". Every screen it gates has a plural
  name, so it was wrong on all five.

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
