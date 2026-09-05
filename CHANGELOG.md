# Release Notes for Craft Analytics

## Unreleased

### Added

- **Connect to Client Reporter.** A new *Client Reporter* section in the plugin
  settings takes a connection code that lets [Client Reporter][cr] pull this
  site's aggregated stats into branded client reports. The read API is signed
  (HMAC, short timestamp window, one-shot nonce) and aggregate-only — the same
  figures the dashboard shows, with no visitor identifiers. Available in every
  edition.

[cr]: https://github.com/coysh-digital/client-reporter

## 2.4.1 - 2026-09-02

### Fixed

- **The GA4 import screen's script 404'd, leaving the property list stuck on
  "Loading properties…".** Its script shared a resources directory with the
  front-end tracker, which is published a file at a time; that left the shared
  directory holding only the tracker, so Craft treated it as already published
  and never copied the import script. The import script now lives in its own
  directory and publishes reliably.

## 2.4.0 - 2026-09-02

### Added

- **An "all time" option on the GA4 history import.** Tick it to bring across
  every day the property holds, without picking a start and end date. The date
  fields are ignored (and greyed out) while it is on.

### Fixed

- **The GA4 import's "What to import" checkboxes rendered twice.** The options
  used hand-rolled markup that drew both a native checkbox and the styled one;
  they now use Craft's checkbox field macros and render once.

## 2.3.1 - 2026-09-02

### Fixed

- **Connecting a GA4 account failed to save on utf8mb4 databases.** The OAuth
  tokens are encrypted before they are stored, and the encrypted bytes are
  binary, which a `utf8mb4` text column rejects (`SQLSTATE[HY000] 1366
  Incorrect string value ... for column 'refreshToken'`). The ciphertext is
  now base64-encoded at rest, matching the plain-text column it lives in.
  Reconnect from **Utilities → Import GA4 History** after upgrading.

## 2.3.0 - 2026-09-02

### Added

- **Import your history from Google Analytics 4.** A new utility (Utilities →
  Import GA4 History) connects to your own Google account, lists your GA4
  properties, and backfills the days before you installed the plugin, so the
  reports do not start empty on the changeover. You connect once with an OAuth
  client ID and secret you create in about five minutes; the wizard walks you
  through it. The Client ID and Client secret are ordinary plugin settings, so
  either can be a `$ENV_VAR` reference, which is the better home for the
  secret. Available in both editions; campaigns, geography and events are
  imported on Pro. See [Importing from GA4](docs/get-started/importing-from-ga4.md).
  - The plugin only ever reads what Google already holds, and only when you
    ask it to: nothing about your visitors is sent, and no Google call is made
    on the plugin's own initiative (C7). The OAuth tokens are stored encrypted
    and are deleted when you disconnect.
  - Days the plugin has already tracked itself are left untouched. Views,
    sessions and events come across exactly as GA4 reported them; unique
    visitor counts are approximate for imported days, since the visitors
    behind an aggregate cannot be imported.

## 2.2.1 - 2026-08-16

### Fixed

- **A spoofed User-Agent version could quarantine a whole drain batch.** The
  browser major version is stored in a `SMALLINT` inside the devices rollup's
  unique key, and it was taken from the User-Agent unchecked — so a UA
  claiming `Chrome/73469` overflowed the column, the batch failed identically
  on every retry, and it was quarantined with every one of its hits uncounted.
  Implausible versions (anything past three digits, or negative) now count as
  version 0, "unknown", the same as a missing one.
- **Accumulated money columns can no longer overflow.** Event values are
  clamped at every boundary they cross — the beacon, server-side
  `trackEvent()` calls (Commerce and Formie included), and the spool decoder —
  and the rollup upserts that sum them (`sumValue`, goal and campaign value
  and session credit) now saturate at their column's maximum instead of
  throwing an out-of-range error that would quarantine the batch. Nightly
  compaction clamps the same way, so a day of hourly rows can no longer sum
  past what the daily row can hold and stall compaction for good.
- **A goal's per-conversion value is now validated against what the schema can
  store**, instead of failing at the database on save.

## 2.2.0 - 2026-08-09

> **Before you upgrade.** Three figures will visibly change, and none of the
> changes is your traffic changing.
>
> **The Crawlers report starts filling up.** On the default spool driver it was
> always empty, because crawler records were being discarded before they could
> be counted. Expect it to go from nothing to a real list overnight.
>
> **Conversions and funnels start reporting on `direct` and `queue` sites.** If
> your site uses either of those write drivers, every goal has read zero since
> the day you created it. Those numbers begin now; there is no backfill,
> because the data to backfill from was never written.
>
> **Some pages may appear twice for a while.** The beacon now records a page
> under the same path the server does, having previously kept the trailing
> slash, the percent-encoding and the pagination segment that Craft strips.
> Historical rows are left exactly as they are, so a page recorded both ways
> keeps both rows until they age out of retention. New traffic lands on one.
>
> No schema change, and no migration to wait for.

### Fixed

- **Crawler hits never survived the drain.** They travel under a reserved
  sentinel where a visitor hash would go, because a crawler is not a person
  and gets nothing to pseudonymise, and the drain rejected that sentinel as a
  corrupt line. The Crawlers report was therefore permanently empty on the
  default driver while working normally on the other two, which is why it went
  unnoticed.
- **Goals and journeys were recorded only by the drain.** A site on the
  `direct` or `queue` write driver reported every conversion as zero and every
  funnel as empty, for as long as it had been running, with nothing to suggest
  the figure came from a code path that never executed.
- **A second compaction pass could delete the day it was compacting.**
  Compaction folds a day's hourly rows into one daily row, and it rebuilt that
  row from the hourly rows alone. After the first pass those rows are gone and
  the daily row is the only record of them, so anything that put a fresh hourly
  row on an old date - a quarantined batch replayed, a spool recovered after an
  outage, seeded history - caused the day's totals to be replaced by whatever
  had just arrived.
- **Campaigns were lost behind a full-page cache.** The beacon has always
  received the UTM parameters and never read them, so on a cached page, where
  the beacon is the only record of the visit, every campaign-tagged landing was
  filed as Direct. The documentation said the opposite. The missing referrer
  stays missing and stays deliberate: a browser-supplied referrer is forgeable,
  a tag in the URL the visitor actually requested is not.
- **The beacon and the server disagreed about what a path is.** The server
  records Craft's own path, which is decoded, trimmed of trailing slashes and
  stripped of the site's base path and any pagination segment. The beacon sent
  the raw one. One page became two rows - the view on one, its dwell and scroll
  on the other - and on a subfolder install, or a multi-site setup that
  separates its sites by path, every page split that way.
- **A campaign behind an entity-encoded link kept only its source.** The path
  normaliser had been hardened for `&amp;` separators and the campaign parser
  had not.
- **A page row created without an element never gained one.** The element was
  written on insert only, so a row first created by a beacon or by the
  entrance and exit write stayed unattached for good and never appeared in the
  content reports. It can now be filled in later, and a resolved element is
  never overwritten.
- **A template with no closing `</body>` tag was counted by nobody.** Craft
  places the tracker before that tag, and where there is none - an HTMX
  partial, a layout whose closing tag comes from a variable - there was no
  tag, no beacon, and a missing nonce that the server read as a cache hit. The
  two cases are now told apart.
- **The drain could double-count a session.** Nothing prevented two drains
  running at once, and the automatic drain only throttles itself for a minute,
  so a pass that outlives its own throttle or a cron entry overlapping one was
  enough for both to close the same session and count it, its bounce and its
  entry and exit twice.
- **A busy site could count a pageview twice.** The nonce that tells the
  beacon the server already counted a view was recorded after the write rather
  than before, and the tracker is deferred, so a beacon arriving in that window
  found nothing to claim. The window was widest exactly when the site was
  busiest.
- **Salt rotation split visitors every time it ran.** Each worker that noticed
  the window had elapsed generated its own salt and wrote it over the others,
  then hashed its own request with the one it had made.
- **Exports could not be read back by PHP.** A value ending in a backslash - a
  path, which anybody can request - was written so that a reader honouring
  backslash escapes swallowed every remaining row into one cell. Spreadsheets
  were unaffected; PHP's own reader was not.
- **The unique-member retention cutoff was read in UTC** against dates written
  in the site's timezone, putting it up to a day out in either direction.
- **Two dashboard widgets ignored per-site permissions**, and the Privacy
  screen's counts covered every site. A user restricted to one site could see
  another site's figures in both places.

### Changed

- **Every dimension a caller can influence is now capped.** Browsers and
  operating systems are parsed from the User-Agent, the five campaign columns
  come from the query string, and site-search terms, event names, outbound
  targets and crawler names all arrive from outside. None of them were capped,
  so each distinct value was a rollup row and a dimension row. The cap is also
  applied before the dimension row is created rather than after, which is what
  it was for.
- **The garbage collector no longer falls over on a large table.** The sweep
  that removes unreferenced dimension values loaded every referenced id in the
  plugin into memory at once, so it failed on exactly the cardinality spike it
  exists to clean up after - and it runs on ordinary web requests as well as
  from cron. Retention deletes are batched for the same reason.
- **The drain reads a spool in slices.** A backlog large enough to matter was
  also large enough to exhaust memory, and the resulting failure counted
  against the batch's retries until it was quarantined and never counted at
  all. Each slice commits on its own, so an interrupted run keeps its progress.
- **Reports are considerably faster.** The trend chart asked the database once
  per day in the range, which is 366 queries for a twelve-month chart on every
  dashboard load; it now asks once. Unique counts no longer load sketch data
  the chosen driver does not read, the Redis and exact drivers no longer build
  a single statement per row of the range, and any range that ended before
  today is remembered for an hour. Today is never cached.
- **The Privacy screen says what uninstalling will destroy.** Uninstalling
  drops every table, which is right and has not changed, but one of them is the
  consent log - the evidence that processing already carried out was lawful,
  and the one thing erasing a visitor deliberately leaves behind.

## 2.1.0 - 2026-08-07

### Added

- **A cron-free fallback for the drain.** The spool driver has always needed
  `craft-analytics/drain/run` on a schedule, or the reports stay empty - fine
  on most hosts, not an option on some shared ones. With the new "Drain
  automatically when there's no cron" setting (on by default), an ordinary
  page request runs the drain instead, at most once a minute and only after
  the visitor already has their page, so it costs them nothing. A backlog
  past 2 MB is left for cron rather than drained inside one request. A real
  cron entry still wins every race and this setting is safe to leave on even
  if you have one.
- **The empty-state on the dashboard now knows the difference between "no
  traffic yet" and "traffic arrived but hasn't been drained".** Previously
  both looked identical - zero on every number, one generic explanation.
  With the spool driver, a spool that still has bytes in it now gets its own
  message pointing at the drain command, cron, and the new fallback setting,
  instead of leaving you to guess. The Real-time screen is unaffected: it
  reads a different, always-current source and a genuine zero there is
  normal.

### Fixed

- **Pro features are enforced by the licence, not only described by it.** The
  GraphQL API has been a Pro feature in the documentation since it shipped,
  but nothing in the code checked the edition: the queries were registered for
  every install, and the only thing in front of them was the schema scope,
  which an admin grants for their own reasons and which says nothing about the
  licence. A Lite site that ticked "Read traffic reports" got the whole API.
  Lite now registers no queries and is not offered the permission in the
  schema editor, since a checkbox granting access to something the licence
  does not include is worse than no checkbox at all.
- The Pro report screens no longer hand their figures to the template on Lite.
  They were already hidden, but by an `{% if %}` in the markup, which makes
  the licence a property of a template rather than of the controller. The
  numbers are now withheld before rendering. This matters most on a site that
  has downgraded, where the Pro rows are still sitting in the tables.

## 2.0.0 - 2026-08-04

> **Before you upgrade.** Goals and funnels move out of project config and into
> the database. The tables already existed - project config was the source of
> truth and the rows were a mirror - so on nearly every site the upgrade is a
> no-op and your goals keep reporting exactly as they did. What changes is
> where they are edited and how they travel: **goals no longer arrive on
> deploy**, and each environment keeps its own. If you rely on creating goals
> in staging and applying them to production, that workflow ends here.
>
> The migration does not touch your project config, because it cannot: writing
> to it throws wherever `allowAdminChanges` is off, which is the case this
> whole change exists for. So the old definitions stay in
> `config/project/craftAnalytics/` until you delete them, and the Goals screen
> says so while they are there. Nothing reads them any more - editing that YAML
> and deploying it will appear to do nothing, which is why the notice exists.

### Added

- **A specific timeframe.** The date picker on every report gains **Custom**,
  which takes two dates and reports on exactly that window. It survives site
  switches, drill-downs, the Pages filters and the CSV export like any preset
  does, and it lives in the URL, so a particular window is a link that can be
  bookmarked or sent on. It is a plain form and two date fields: no JavaScript
  is involved, which matters on a control panel where inline scripts are
  routinely deferred out of existence by front-end optimisers.

  A custom window spans at most 400 days - just past the 12 months the picker
  already offered. Unique visitors are counted per day, so a range costs one
  query per day in it and an unbounded range is an unbounded amount of work.
  Dates in the future are pulled back to today, dates entered the wrong way
  round are swapped rather than refused, and anything unreadable falls back to
  30 days rather than erroring.

  The GraphQL `period` argument and the Twig methods take the same
  `YYYY-MM-DD:YYYY-MM-DD` form. `craft-analytics/report/send --period` takes it
  too, but *rejects* a date it cannot read instead of falling back: a console
  user who mistypes a date wants to be told, not handed a month of the wrong
  figures. The scheduled report period and the Overview widget's range stay
  preset-only by design - an absolute window on a recurring email or a pinned
  widget freezes, and reports the same figures forever.

### Changed

- **Goals and funnels can be created in production.** They were stored in
  project config on the reasoning that a goal is configuration and should
  arrive on deploy like a section does. That was right about the object and
  wrong about the people: project config is read-only wherever
  `allowAdminChanges` is off, so the goals screen in production could only
  refuse, and the person who wanted a goal was a deploy away from the person
  who could add one. A goal is closer to a saved report than to a section. It
  now lives in the database, and every environment owns its own.

### Fixed

- The Locations map could fail in a way that could not report itself. The "the
  map could not be drawn" message was rendered hidden and revealed by the same
  script that draws the map - so when that script was the thing that failed to
  arrive, nothing was left on the page to say so, and the card was simply
  blank with an empty console. The message now starts visible and the script
  hides it, which is the only arrangement that survives its own failure. If you
  are looking at a blank Locations card after upgrading, it will now tell you
  what to check.
- A duplicate goal or funnel handle produced a database integrity error rather
  than a form error. Both columns are uniquely indexed and creating goals is
  now something people do rather than something they deploy, so picking a
  handle somebody already used stopped being hypothetical.
- A funnel step naming a goal that does not exist was saved as a funnel with
  that step silently missing, which reports drop-off that never happened. It is
  refused at the form instead.

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
