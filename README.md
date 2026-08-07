# Craft Analytics

Our aim was to build an analytics plugin that is lightweight, privacy-focused,
and feature-rich, with GDPR compliance at the heart of its design. Craft
Analytics is first-party and cookieless by default, with no added
time-to-first-byte and storage that does not grow with your traffic.

Everything runs on your own server: no account, no CDN script, and no data sent
anywhere.

It did not start life as a product. We built it for our own client sites and it
has been quietly running them for a while, so it comes to the public already
tested in the wild rather than fresh off the workbench. Having leaned on it day
to day, we decided it was worth releasing to everyone.

## What it does differently

- **No cookies, so no banner.** Visitors are counted with a hash built from a
  salt that is replaced every 24 hours. Nothing is stored on the device.
- **No IP addresses.** The address is used in memory to compute the hash and
  then dropped. It is never written to a table, a log or a cache key.
- **No per-visitor records.** The database holds counts, so there is nothing to
  hand over, delete or leak.
- **No effect on page speed.** Capture happens after the response is flushed.
  Measured on the dev harness: 0.46 ms TTFB difference, within the noise, and
  42 µs at the 99th percentile for the capture itself.
- **Storage that does not track traffic.** A page viewed a million times takes
  the same one row as a page viewed twice. Growth is cardinality × time.
- **Accurate behind a cache.** Blitz, Cloudflare and Varnish are all handled,
  with no cache configuration.
- **Reports built on your content model.** Traffic by section, entry type and
  author, because Craft already knows all of that.

## Requirements

- Craft CMS ^5.0
- PHP ^8.2
- MySQL 8+ or PostgreSQL 13+
- Redis recommended (session hot layer, faster unique counting), not required

## Installation

```bash
composer require coyshdigital/craft-analytics
php craft plugin/install craft-analytics
```

Then put the drain on your cron, or the reports stay empty:

```
*/5 * * * * php craft craft-analytics/drain/run
0 4 * * *   php craft craft-analytics/gc/run
```

No cron on your host? The plugin can drain itself from ordinary traffic
instead - see "Drain automatically when there's no cron" in the plugin
settings. It's slower and on by default, so most installs never need to think
about this at all.

Full instructions: [Installation & setup](https://coysh.digital/plugins/craft-analytics/docs/get-started/installation)

## Before you compare the numbers

**Unique visitors are counted on a daily-unique basis.** Somebody who visits on
three days counts three times, because the hashing salt rotates every 24 hours
and removes the link between their days.

This is what allows the plugin to work without a cookie banner, and it means the
figure is not comparable with a Google Analytics "users" count. Sessions and
pageviews are exact, and the unique trend is reliable over time. The control
panel notes this on every screen that shows the number.

[How visitors are counted](https://coysh.digital/plugins/craft-analytics/docs/privacy/how-counting-works)
explains it in full.

**Sources lean on the server seeing the request.** The referrer is read when PHP
renders the page; the beacon deliberately never sends one, because a
browser-supplied referrer is forgeable. Behind a full-page cache, sessions that
enter on a cached page are reported as Direct. Pageview counts are unaffected,
and so are UTM campaigns, which travel in the URL - so behind an aggressive
cache, tag the inbound links you care about.
[Static & edge caching](https://coysh.digital/plugins/craft-analytics/docs/configuration/caching)
has more details.

## Documentation

The full documentation lives at
**[coysh.digital/plugins/craft-analytics/docs](https://coysh.digital/plugins/craft-analytics/docs/)**.

- [Installation & setup](https://coysh.digital/plugins/craft-analytics/docs/get-started/installation)
- [How tracking works](https://coysh.digital/plugins/craft-analytics/docs/get-started/tracking-modes)
- [Static & edge caching](https://coysh.digital/plugins/craft-analytics/docs/configuration/caching) -
  read this if you run Blitz or a CDN
- [Locations & the geo database](https://coysh.digital/plugins/craft-analytics/docs/configuration/geolocation)
- [How visitors are counted](https://coysh.digital/plugins/craft-analytics/docs/privacy/how-counting-works)
- [Twig API](https://coysh.digital/plugins/craft-analytics/docs/developers/twig) and
  [GraphQL API](https://coysh.digital/plugins/craft-analytics/docs/developers/graphql)
- [Troubleshooting](https://coysh.digital/plugins/craft-analytics/docs/troubleshooting)

## Lite vs Pro

Lite is a usable analytics tool on its own, not a trial of Pro. Pro only gives
you access to better integrations and goals/funnels.

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

Upgrading is a licence change. Both editions use the same tables, so there is
no migration to run and your existing data carries over.

## A note on AI

We used AI tooling while building this: to talk through approaches, to take some
of the grind out of the groundwork, and to help draft this documentation. What
it is not is code written by a machine and shipped unread. Every line was
reviewed, tested and put in place by a developer who understood it and stands
behind it. The design decisions, the trade-offs and the final code are ours.

## Licence

Commercial, under the [Craft licence](LICENSE.md). Third-party components and
their licences are listed in [THIRD-PARTY-LICENSES.md](THIRD-PARTY-LICENSES.md)
and [NOTICE.md](NOTICE.md).
