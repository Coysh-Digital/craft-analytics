# The control panel

Analytics lives under **Analytics** in the CP nav: Dashboard, Real-time,
Pages, Sources, Devices.

## Screens

**Dashboard** - headline numbers (pageviews, unique visitors, sessions,
bounce rate, average session) each compared against the previous period of the
same length, a traffic chart, and the top of each report.

**Real-time** - visitors active inside the session window, read entirely from
the session cache. **No database queries at all**, which is a pleasant
side-effect of keeping sessions in the hot layer. Refreshes every 15 seconds,
and stops polling when the tab is hidden.

**Pages** - views, entrances, exits, bounce rate and average time per page,
linked to the entry where one matched.

**Sources** - sessions by channel (direct / search / social / referral /
campaign / internal) and by referring host. Only the *host* is ever stored,
never the full referrer URL, which can carry search terms.

**Devices** - device type, browser and operating system.

## Where the content is

Two things a generic analytics tool structurally cannot do, because it has no
idea what an entry is:

- **Entry editor sidebar** - views, visitors and a 30-day sparkline for that
  entry, in the entry itself.
- **“Views (30d)” column** on the entries index. Craft only shows table
  columns a user has selected, so turn it on via **View → Table columns**.

There's also a **dashboard widget** for Craft's own dashboard.

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
