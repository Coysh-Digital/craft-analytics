# The control panel

Analytics lives under **Analytics** in the CP nav: Dashboard, Real-time,
Pages, Sources, Devices.

## Screens

**Dashboard** - headline numbers (pageviews, unique visitors, sessions,
bounce rate, average session) each compared against the previous period of the
same length, a traffic chart, and the top of each report. A live banner at the
top shows how many visitors are on the site right now, updating every 15
seconds, and links through to the Real-time screen.

**Real-time** - visitors active inside the session window, read entirely from
the session cache. **No database queries at all**, which is a pleasant
side-effect of keeping sessions in the hot layer. Refreshes every 15 seconds,
and stops polling when the tab is hidden.

**Pages** - views, entrances, exits, bounce rate and average time per page.
Each path links to its own screen (below); where the path matched a Craft
entry, an edit link sits beside it. Tick up to four rows and press **Compare
selected** to plot them against each other over time — the comparison lives in
the URL, so it can be bookmarked or sent to someone.

**A single page** - reached by clicking any path. Views and unique visitors
over time, the headline numbers with period-on-period change, how people
reached this page, how far down they read, and the events and outbound clicks
recorded on it. Works for paths that are not Craft entries at all: a
template-only route, a search results page, a URL that only ever 404'd.

Two figures on that screen need reading carefully. **Entrances** counts
sessions that *began* on this page, so a page people reach part-way through a
visit will show few — that is the page's role, not a fault. **Bounce rate** is
derived from entrances for the same reason, and shows `-` when there are none:
a bounce is only ever counted against the page a session entered on.

**How people reached this page** fills forward only. It is derived from the raw
hits, which are aggregated away as they arrive, so it cannot be worked out
retrospectively — the card states the date collection began. The referrer
recorded is the one the *session* arrived by, not the previous page on your own
site, so an interior page reports how the visit started.

**Sources** - sessions by channel (direct / search / social / referral /
campaign / internal) and by referring host, with a stacked chart of how that
mix moved over the period. Only the *host* is ever stored, never the full
referrer URL, which can carry search terms.

**Devices** - device type, browser and operating system, with the device split
as a doughnut.

## Choosing a period

Every report screen carries the same picker: today, yesterday, the last 7, 30
or 90 days, the last 12 months, and **Custom** for an absolute window. Whatever
you pick follows you across site switches, drill-downs, the Pages filters and
the CSV export, and it lives in the URL, so a particular window is a link you
can bookmark or send to someone.

A custom window can span at most **400 days**. That is a little more than the
12 months the picker already offers, and the cap is there because unique
visitors are counted per day - a range is one query per day in it, so an
unbounded one is an unbounded amount of work. Dates in the future are pulled
back to today, and dates entered the wrong way round are swapped rather than
rejected.

One thing worth knowing: a single day only gets an hour-by-hour chart while it
is still inside the hourly retention window (below). Ask for one day from three
months ago and you get a single daily figure, because the hourly rows for that
day were compacted away and drawing 24 zeroes would look like an outage rather
than like housekeeping.

## When people visit

The dashboard's heatmap plots day of the week against hour of the day. It
covers the **hourly retention window only** — `hourlyWindowDays`, seven days by
default — and says so on the card, because that is the period still held hour
by hour. Older days are compacted to a single daily total to keep the tables
proportional to cardinality rather than traffic, which leaves nothing to put on
an hourly axis. Raise `hourlyWindowDays` for a longer heatmap, at 24 times the
rows for the extra window.

## Charts and accessibility

Charts are drawn with Chart.js, vendored locally — there is no CDN request and
nothing loads from outside your server. It is registered only on the screens
that draw one; Craft's own entry editor and dashboard widgets use
server-rendered SVG sparklines and download no JavaScript from this plugin.

Every chart ships a table of the same numbers, hidden from view but present for
a screen reader, and each series is named in the legend and in its tooltip — so
nothing is ever identified by colour alone. If a chart cannot be drawn, that
table becomes visible: a failure shows you the figures rather than an apology.
Animation is disabled when the operating system asks for reduced motion.

## Where the content is

Two things a generic analytics tool structurally cannot do, because it has no
idea what an entry is:

- **Entry editor sidebar** - views, visitors and a 30-day sparkline for that
  entry, in the entry itself.
- **“Views (30d)” column** on the entries index. Craft only shows table
  columns a user has selected, so turn it on via **View → Table columns**.

## Dashboard widgets

Two widgets for Craft's own dashboard, both under the *View analytics*
permission and both scoped to the currently selected site:

- **Analytics** - a compact overview: a row of headline figures, a views
  sparkline and a link into the full report. Its settings let you choose the
  **period** (today through the last 12 months) and which **figures** to show.
  Any of views, visitors, sessions, bounce rate, pages per session, average
  session and average time on page can be turned on; it ships showing views,
  visitors and bounce rate.
- **Analytics real-time** - visitors on the site right now and the pages
  they're on, read entirely from the session cache and refreshed every 15
  seconds. The same live figure the Real-time screen shows, on your dashboard.

## Export

Every report exports to CSV, and to JSON with `?format=json`. Exports carry
**exact** values - the screens abbreviate (`13k`) for readability, files are
for working with. The JSON payload states `uniquesAccuracy` so a consumer
cannot mistake an estimate for a count.

Requires the *Export analytics data* permission.

## Permissions

| Permission | Grants |
|---|---|
| View analytics | The Analytics screens, limited to sites the user can edit |
| ↳ View analytics for all sites | Every site's traffic, regardless of content permissions |
| ↳ Export analytics data | The export endpoints |
| Manage plugin settings | The settings screen |

Site scoping is enforced in the controller, not just hidden in the UI: a user
who can only edit one site's content cannot read another site's traffic by
editing a URL (C8).

## About the numbers

- **Unique visitors is an estimate**, and the CP says by how much (±1.6% by
  default; ±0.8% with Redis or a higher `hllPrecision`; *exact* with the
  `exact` driver). It is never presented as a count.
- **Multi-day uniques are on a daily-unique basis.** The visitor salt rotates
  daily and the old one is destroyed, so a visitor returning on another day
  counts once per day. That is the cost of not needing a consent banner, and
  the tooltip on the KPI says so. See [storage](../configuration/retention.md).
- **Sessions appear when they close**, not when they start - a session's
  duration and bounce status aren't known until it ends. So a brand-new site
  shows pageviews immediately and sessions after the inactivity window plus a
  drain.
- **Time on page needs the beacon.** In `server` tracking mode it stays blank
  rather than showing a fabricated number.
- **Sources need the server to see the request.** The referrer is read when PHP
  renders the page; the beacon never sends one, because a browser-supplied
  referrer is forgeable. A session entering on a cached page is reported as
  Direct. UTM campaigns travel in the URL and are unaffected. See
  [static & edge caching](../configuration/caching.md).
- **`__other__`** is where values past the daily cardinality cap are folded.
  No views are lost - they're just not attributed individually.

## The charts

Hand-rolled SVG, rendered server-side. No charting library, no CDN, nothing to
download (C7); the only client-side JS is the hover layer.

Colours are Craft's own blue-600 and teal-600 - a pair validated for
colour-vision deficiency (CVD ΔE 20.7, both above 3:1 contrast on the pane).
Series are named in the legend and in the tooltip, and every chart has a table
of the same data beneath or beside it, so nothing is ever encoded by colour
alone. Table bars are a background wash with the number always spelled out
next to them.
