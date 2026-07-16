# Craft Analytics

Privacy-first, consent-aware analytics for Craft CMS 5. First-party,
cookieless by default, with no added time-to-first-byte and a database that
doesn't grow with your traffic.

It runs entirely on your own infrastructure. No account, no CDN script, no
data leaving your server, nothing phoning home.

## Why

Most analytics makes you choose between knowing what's happening on your site
and being straight with the people using it. This doesn't:

- **No cookies, no banner.** Visitors are counted with a hash built from a salt
  that's destroyed and replaced every 24 hours. Nothing is stored on the
  device, so there's nothing to consent to.
- **No IP addresses.** Not in a table, not in a log, not in a cache key. The
  address is used in memory to compute a hash, then discarded.
- **Nothing per-visitor.** The database holds counts, not people - so there's
  no record to hand over, delete, or leak.
- **It won't slow your site down.** Capture happens after the response is
  flushed. Measured on the dev harness: **-0.46 ms** TTFB difference (noise),
  and 42 µs at the 99th percentile for the capture itself.
- **Your database doesn't grow with traffic.** A page viewed a million times is
  the same one row as a page viewed twice. Growth is cardinality × time.
- **It works behind a cache.** Blitz, Cloudflare, Varnish - a cached page is
  still counted, with no cache configuration at all.
- **It knows your content model.** Traffic by section, entry type and author,
  because it's a Craft plugin and Craft already knows this.

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

Then put the drain on your cron, or your reports will stay empty:

```
*/5 * * * * php craft craft-analytics/drain/run
0 4 * * *   php craft craft-analytics/gc/run
```

Full instructions: [docs/get-started/installation.md](docs/get-started/installation.md)

## The one thing to know

**Unique visitors are counted on a daily-unique basis.** Somebody who visits on
three days counts three times, because the hashing salt rotates every 24 hours
and destroys the link between their days.

That's the property that buys you the missing cookie banner, and it means the
number isn't comparable with a Google Analytics "users" figure. Sessions and
pageviews are exact; the unique trend is entirely sound. The control panel says
so on every screen that shows it, rather than letting you find out later.

[The full explanation](docs/privacy/how-counting-works.md) is worth five
minutes before anyone puts the number in a report.

## Documentation

- [Installation & setup](docs/get-started/installation.md)
- [How tracking works](docs/get-started/tracking-modes.md)
- [Static & edge caching](docs/configuration/caching.md) - read this if you run
  Blitz or a CDN
- [How visitors are counted](docs/privacy/how-counting-works.md)
- [Twig API](docs/developers/twig.md) and [GraphQL API](docs/developers/graphql.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Everything else](docs/)

## Lite vs Pro

Lite is genuinely useful on its own - pageviews, visitors, sessions, bounce
rate, real-time, pages, sources, devices, content-model reports, crawler
reporting and export.

Pro adds campaigns and attribution, geography, events, goals and funnels,
Formie and Commerce integrations, consent-aware Tier 2, emailed summaries and
the GraphQL API.

## Development

```bash
composer install
composer check-cs   # ECS (Craft ruleset)
composer phpstan    # PHPStan level 8
composer test       # Pest
```

A ddev-based Craft 5 dev harness lives in `dev/` - see `dev/README.md`.
Contributor rules, architecture decisions and the constraints that drive them:
[CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

Commercial, under the [Craft licence](LICENSE.md). Third-party components and
their licences are listed in [THIRD-PARTY-LICENSES.md](THIRD-PARTY-LICENSES.md)
and [NOTICE.md](NOTICE.md).
