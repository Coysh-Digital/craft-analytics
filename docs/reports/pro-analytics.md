# Pro analytics

Campaigns, locations, events, outbound links, downloads, site search and
scroll depth. All Pro, all gated at the service layer rather than in the UI.

## Campaigns

Links tagged with `utm_source` (and optionally `utm_medium`, `utm_campaign`,
`utm_term`, `utm_content`) are attributed to the **session**, not the
pageview - a campaign brings somebody to the site once, not once per page
they then read.

Values are lower-cased and trimmed, so `Newsletter` and `newsletter` stay one
campaign rather than becoming two.

**UTM and click-id parameters are stripped from the recorded path.** They
describe how somebody arrived, not which page they arrived at, and they are
captured separately. Leaving them on would split one page into a row per
campaign and inflate path cardinality for data recorded elsewhere. `gclid`,
`fbclid`, `msclkid` and friends are stripped for the same reason - but they
are *not* read as campaigns, because a click id identifies an ad click, not a
source, and inventing a campaign from one would be making data up.

### Attribution models

| Model | Credit |
|---|---|
| `last-click` (default) | The most recent touch takes it all |
| `first-click` | The touch that first brought them takes it all |
| `linear` | Split evenly across every touch |

The model only matters for a session with more than one touch, which is
uncommon - with a single touch, every model gives an identical answer.

One session is always credited as exactly one session. Under `linear`, a
session with three touches contributes 0.333 to each, so the column can be
fractional and the total still equals the sessions you actually had. An
attribution report that credits a session to three campaigns *in full* is
claiming traffic the site never received.

### What this cannot do, and why

**There is no cross-session attribution window for anonymous visitors, and we
will not fake one.**

The Tier-1 visitor hash is re-salted every 24 hours and the old salt
destroyed, so there is no way to know that today's visitor is the person who
clicked an ad last week. That is the same property the banner-free claim rests
on - you cannot have both. Attribution is therefore within a session.

Consented (Tier-2) visitors keep a durable identifier and *can* be followed
across sessions; if multi-touch attribution over weeks matters to you, that is
what it costs. See [privacy](../privacy/README.md).

## Locations

Setting this up needs a database file you install yourself - see
[Locations & the geo database](../configuration/geolocation.md).

Country and region, resolved from a database **on your own server**. No lookup
service is called, nothing leaves the machine, and the address is resolved and
discarded inside a single call frame - only the country code and region name
are stored (C5, C7).

```bash
php craft craft-analytics/geo/install --url=<db-ip lite url>
php craft craft-analytics/geo/status
```

Nothing downloads on its own. The command exists so that fetching a database
is a decision an operator makes.

| Database | Licence | Notes |
|---|---|---|
| **DB-IP Lite** (recommended) | CC BY 4.0 | Free and redistributable. Attribution to DB-IP is required, and the CP shows it for you. |
| MaxMind GeoLite2 | MaxMind's own | Free with an account. We can read the file; we can't ship it for you. |

Both are monthly releases - re-run the install command to update.

## Events

```js
craftAnalytics.event('signup');
craftAnalytics.event('purchase', { value: 49.99 });
```

Values are clamped and event names stripped of control characters server-side.
The client can say anything; an event worth 10²⁰ is not a sale.

## Outbound links and downloads

Tracked automatically when enabled. Clicks are caught in the capture phase, so
they are recorded even when the page's own handlers stop propagation or
navigate away. A click is genuinely a separate event from the pageview, so it
sends its own beacon - there is no way to report a click on the way out of the
page it left.

Downloads are same-host links to a file with one of the extensions in
`downloadExtensions`.

## Scroll depth

Four buckets - 25/50/75/100 - because "how far down did people get" has four
useful answers and 101 useless ones.

**It rides the pageview beacon** rather than sending its own request, via an
extension point in the tracker. One request per pageview is the budget, and it
stays the budget. Reaching a depth counts once per pageview, and reaching 75%
means they also passed 25% and 50%.

## Site search

Read from the URL of your search page - **no JavaScript required**, so it
works for visitors who have it turned off:

```php
'trackSiteSearch' => true,
'siteSearchPath' => '/search',
'siteSearchParam' => 'q',
```

Terms are lower-cased and trimmed so one search stays one search. Whether a
search found anything is something only your site knows; report it yourself
with `craftAnalytics.event('search:zero-results')`.

## The scripts

| File | Loaded when | Size (gzipped) |
|---|---|---|
| `tracker.js` | always | **1,536 bytes** (budget 2,048) |
| `consent.js` | Pro **and** consent enabled | 1,258 bytes |
| `pro.js` | Pro **and** events enabled | 1,686 bytes |

Split deliberately: a Lite site downloads only `tracker.js`, and pays nothing
for features it doesn't have. Only `tracker.js` is under the C3 budget,
because only `tracker.js` is what every visitor to every site gets.

## Settings

All of these are on the **What gets tracked** section of the settings screen
(Settings -> Plugins -> Craft Analytics), and every one can also be set in
`config/craft-analytics.php` to differ between environments.

| Setting | Default |
|---|---|
| `enableCampaigns` | `true` |
| `attributionModel` | `last-click` |
| `enableGeo` | `false` (needs the database installed) |
| `enableEvents` | `true` |
| `trackOutbound` / `trackDownloads` / `trackScroll` | `true` |
| `trackSiteSearch` | `false` |
