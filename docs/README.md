---
title: Craft Analytics
description: Privacy-first, consent-aware analytics that lives inside Craft CMS.
---

# Craft Analytics

Analytics for Craft CMS that doesn't need a cookie banner, doesn't send your
visitors to anyone else's servers, and knows the difference between a URL and
an Article in your Blog section.

It runs entirely on your own infrastructure. There's no account to create, no
script from a CDN, no data leaving your server, and nothing phoning home -
because there's nowhere for it to phone.

## Why it exists

Most analytics makes you choose between knowing what's happening on your site
and being straight with the people using it. This doesn't:

- **No cookies, no banner.** Visitors are counted with a hash that's destroyed
  and remade every 24 hours. Nothing is stored on their device, so there's
  nothing to consent to.
- **No IP addresses.** Not in a table, not in a log, not in a cache key. The
  address is used in memory to work out a hash, and then it's gone.
- **Nothing per-visitor.** The database holds counts, not people. There's no
  record of anyone to hand over, delete, or leak.
- **It doesn't slow your site down.** The work happens after the page has been
  sent. Measured: -0.46 ms difference in time-to-first-byte, which is noise.
- **Your database doesn't grow with your traffic.** A page viewed a million
  times is the same one row as a page viewed twice.
- **It knows your content model.** Traffic by section, entry type and author,
  because it's a Craft plugin and Craft already knows all this.

## Start here

- **[Installation & setup](get-started/installation.md)** - ten minutes, and
  don't skip the cron
- **[How tracking works](get-started/tracking-modes.md)** - the modes, and what
  each one costs you
- **[Static & edge caching](configuration/caching.md)** - **read this if you
  run Blitz, Cloudflare or Varnish**

## Reports

- [The screens](reports/README.md) - what everything means
- [Content](reports/content.md) - by section, entry type and author
- [Goals](reports/goals.md) - counting what matters *(Pro)*
- [Funnels](reports/funnels.md) - where people leave *(Pro)*
- [Campaigns, geography & events](reports/pro-analytics.md) *(Pro)*

## Privacy

- [How visitors are counted](privacy/how-counting-works.md) - **the one thing
  that will surprise you**
- [Privacy & compliance](privacy/README.md) - the banner-free argument,
  consent, DSARs and the paperwork

## Configuration

- [All settings](configuration/settings.md)
- [Retention & storage](configuration/retention.md)
- [Caching](configuration/caching.md)

## For developers

- [Twig API](developers/twig.md) - "most read" in six lines
- [Performance](developers/performance.md) - how the zero-TTFB claim works and
  what it measures at
- [Attribution & prior art](developers/attribution.md)

## When something's wrong

- **[Troubleshooting](troubleshooting.md)** - starting with "the reports are
  empty", which is almost always the cron

## Lite vs Pro

Lite is genuinely useful on its own. It isn't a demo.

| | Lite | Pro |
|---|---|---|
| Pageviews, visitors, sessions, bounce rate | ✅ | ✅ |
| Real-time | ✅ | ✅ |
| Pages, sources, devices | ✅ | ✅ |
| Content: sections, entry types, authors | ✅ | ✅ |
| Crawler reporting | ✅ | ✅ |
| CSV & JSON export | ✅ | ✅ |
| Entry sidebar & dashboard widgets | ✅ | ✅ |
| Campaigns & attribution | | ✅ |
| Geography | | ✅ |
| Events, outbound clicks, downloads, scroll | | ✅ |
| Goals & funnels | | ✅ |
| Formie & Commerce integrations | | ✅ |
| Consent-aware Tier 2 | | ✅ |
| Emailed summaries | | ✅ |
| GraphQL API | | ✅ |

Upgrading is a licence change. Same tables, no migration, and everything
you've already collected is still there.
