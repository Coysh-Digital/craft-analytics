# Installation

## Requirements

- Craft CMS ^5.0
- PHP ^8.2
- MySQL 8+ or PostgreSQL 13+
- Redis is recommended (used for the session hot layer and native
  HyperLogLog unique counting when available) but not required.

## Install

```bash
composer require coyshdigital/craft-analytics
php craft plugin/install craft-analytics
```

Or via the Craft Plugin Store in the control panel.

## After installing

1. Review **Settings → Craft Analytics**, or copy
   `vendor/coyshdigital/craft-analytics/src/config.php` to
   `config/craft-analytics.php` to manage settings per environment.
2. With the default `spool` write driver, schedule the drain to run every
   minute — without it, hits accumulate in the spool and never become
   statistics:
   ```cron
   * * * * * /usr/bin/php /path/to/craft craft-analytics/drain/run
   ```
   Or run it continuously under a process supervisor:
   ```bash
   php craft craft-analytics/drain/run --watch --interval=60
   ```
   The drain is safe to interrupt at any point: batches are claimed by rename
   and committed exactly once, so killing it mid-run never double-counts or
   loses data.
3. Verify the wiring:
   ```bash
   php craft craft-analytics/info
   ```

## Editions

- **Lite** — cookieless, banner-free analytics: pageviews, uniques, sessions,
  sources, devices, real-time, entry stats, CSV/JSON export.
- **Pro** — consent-aware tier-2 tracking, campaigns, goals, funnels,
  Craft-native content dimensions, integrations, external database, and more.

Editions are managed through the Craft Plugin Store.
