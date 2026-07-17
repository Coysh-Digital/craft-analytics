# Craft Analytics

Privacy-first, consent-aware analytics for Craft CMS 5. First-party, cookieless
by default, with no added time-to-first-byte and storage that does not grow with
your traffic.

Everything runs on your own server: no account, no CDN script, and no data sent
anywhere.

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

Full instructions: [docs/get-started/installation.md](docs/get-started/installation.md)

## Before you compare the numbers

**Unique visitors are counted on a daily-unique basis.** Somebody who visits on
three days counts three times, because the hashing salt rotates every 24 hours
and removes the link between their days.

This is what allows the plugin to work without a cookie banner, and it means the
figure is not comparable with a Google Analytics "users" count. Sessions and
pageviews are exact, and the unique trend is reliable over time. The control
panel notes this on every screen that shows the number.

[How visitors are counted](docs/privacy/how-counting-works.md) explains it in
full.

## Documentation

- [Installation & setup](docs/get-started/installation.md)
- [How tracking works](docs/get-started/tracking-modes.md)
- [Static & edge caching](docs/configuration/caching.md) - read this if you run
  Blitz or a CDN
- [Locations & the geo database](docs/configuration/geolocation.md)
- [How visitors are counted](docs/privacy/how-counting-works.md)
- [Twig API](docs/developers/twig.md) and [GraphQL API](docs/developers/graphql.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Everything else](docs/)

## Lite vs Pro

Lite covers pageviews, visitors, sessions, bounce rate, real-time, pages,
sources, devices, the content-model reports, crawler reporting and export.

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

Unit tests run without a database. The integration suite needs one, and runs
against both engines:

```bash
CRAFT_ANALYTICS_TEST_DRIVER=mysql \
CRAFT_ANALYTICS_TEST_MYSQL_DSN="mysql:host=127.0.0.1;dbname=craftanalytics_test" \
CRAFT_ANALYTICS_TEST_DB_USER=root \
CRAFT_ANALYTICS_TEST_DB_PASSWORD=root \
composer test
```

## Licence

Commercial, under the [Craft licence](LICENSE.md). Third-party components and
their licences are listed in [THIRD-PARTY-LICENSES.md](THIRD-PARTY-LICENSES.md)
and [NOTICE.md](NOTICE.md).
