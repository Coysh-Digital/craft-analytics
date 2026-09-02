---
title: Craft Analytics
description: Lightweight, privacy-focused, feature-rich analytics for Craft CMS, designed with GDPR compliance in mind.
---

# Craft Analytics

My aim was to build an analytics plugin that is lightweight, privacy-focused,
and feature-rich, with GDPR compliance at the heart of its design. Craft
Analytics runs on your own server, understands your Craft content, and gives
you useful reports without sending visitor data to a third-party platform.

It also understands your content: it can tell you how the Blog section is
doing, not just that `/blog/some-slug` got 400 views.

It did not begin as a product. I built it for our own client sites and it has
been running them for a while, so it arrives already tested in the wild rather
than fresh off the workbench. After living with it day to day I decided it was
worth putting in front of everyone.

## What it does differently

- **No cookies, so no banner.** Visitors are counted with a hash built from a
  salt that is thrown away and replaced every 24 hours. Nothing is stored on
  anyone's device.
- **No IP addresses.** The address is used in memory to work out that hash and
  then dropped. It is never written to a table, a log or a cache key.
- **No per-visitor records.** The database holds counts. If someone asks what
  you know about them, the answer is nothing.
- **No effect on page speed.** Everything happens after the response has been
  sent. We measure a 0.46 ms difference in time-to-first-byte, which is within
  the noise.
- **Storage that does not track traffic.** A page viewed a million times takes
  up the same one row as a page viewed twice.
- **Reports built on your content model.** Traffic by section, entry type and
  author, because Craft already knows all of that.

## Start here

- **[Installation & setup](get-started/installation.md)** - about ten minutes.
  The cron step is the one people miss.
- **[How tracking works](get-started/tracking-modes.md)** - the modes, and what
  each one costs you
- **[Static & edge caching](configuration/caching.md)** - **read this if you
  run Blitz, Cloudflare or Varnish**
- **[Importing from GA4](get-started/importing-from-ga4.md)** - bring your
  history across so the reports do not start empty

## Reports

- [The screens](reports/README.md) - what everything means
- [Content](reports/content.md) - by section, entry type and author
- [Goals](reports/goals.md) - counting what matters *(Pro)*
- [Funnels](reports/funnels.md) - where people leave *(Pro)*
- [Campaigns, geography & events](reports/pro-analytics.md) *(Pro)*

## Privacy

- [How visitors are counted](privacy/how-counting-works.md) - **read this
  before comparing the numbers with Google Analytics**
- [Privacy & compliance](privacy/README.md) - the banner-free argument,
  consent, DSARs and the paperwork

## Configuration

- [All settings](configuration/settings.md)
- [Retention & storage](configuration/retention.md)
- [Caching](configuration/caching.md)
- [Locations & the geo database](configuration/geolocation.md) *(Pro)*

## For developers

- [Twig API](developers/twig.md) - popular entries, view counts, site totals
- [Extending](developers/extending.md) *(Pro)* - segment your traffic by what
  your own site knows, use your own IDs for consented visitors, and the events
  you can already listen to
- [GraphQL API](developers/graphql.md) *(Pro)*
- [Performance](developers/performance.md) - how the zero-TTFB claim works and
  what it measures at
- [Attribution & prior art](developers/attribution.md)

## When something's wrong

- **[Troubleshooting](troubleshooting.md)** - starting with empty reports,
  which is almost always the cron

## Lite vs Pro

Lite is a usable analytics tool on its own, not a trial of Pro.

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
| Segments & the extension API | | ✅ |
| Emailed summaries | | ✅ |
| GraphQL API | | ✅ |

Upgrading is a licence change. Both editions use the same tables, so there is
no migration to run and your existing data carries over.

## A note on AI

In the spirit of being upfront: I used AI tooling while building this plugin, to
think through approaches, to speed up some of the groundwork, and to help draft
these docs. It was a tool in the workshop, not the builder. Every line of code
was reviewed, tested and put in place by a developer who understood it, and
nothing shipped that I could not explain and stand behind. The judgement calls
and the final code are human.
