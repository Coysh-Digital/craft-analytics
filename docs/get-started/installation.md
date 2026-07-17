---
title: Installation & setup
description: Getting Craft Analytics installed, tracking, and showing you real numbers.
---

# Installation & setup

Craft Analytics needs Craft 5.0 or later and PHP 8.2 or later. It works on
MySQL 8+ and PostgreSQL 13+, and it doesn't care which one you use.

## Install it

From the Plugin Store, search for **Craft Analytics** and click install.

Or from the terminal, which is usually quicker:

```bash
composer require coyshdigital/craft-analytics
php craft plugin/install craft-analytics
```

That installs the plugin. One more step before it collects anything useful.

## Put the drain on your cron

Without this, the plugin records pageviews but never adds them up, and your
reports stay empty.

```
*/5 * * * * /usr/bin/php /path/to/your/site/craft craft-analytics/drain/run
```

Every five minutes is a sensible default: the numbers are at most five minutes
behind and each run stays small. Every minute is fine too.

While you're in there, add the housekeeping job:

```
0 4 * * * /usr/bin/php /path/to/your/site/craft craft-analytics/gc/run
```

That compacts old hourly rows into daily ones and deletes anything past your
retention period. Once a day at a quiet hour is enough.

::: tip
Not sure the drain is running? **Analytics → Dashboard** will tell you when it
last ran. If that says "never", the cron isn't firing, and nothing else you do
will make the numbers appear.
:::

## Check it's working

1. Open your site in a browser. Not the control panel - the actual site.
2. Wait for the drain to run, or force it:
   ```bash
   php craft craft-analytics/drain/run
   ```
   It will tell you what it did: `Drained 1 hit(s) from 1 batch(es)...`
3. Go to **Analytics → Real-time**. You should be there.

If the drain reports 0 hits, see [Troubleshooting](../troubleshooting.md).
Testing with `curl` is the most common explanation.

## What you get out of the box

There is nothing to configure. The defaults are:

- **Hybrid tracking**, the only mode that is accurate behind a cache and still
  counts people who block scripts.
- **No cookies**, local storage or device identifiers, so there is nothing to
  consent to.
- **No IP addresses stored** in a table, a log or a cache key.
- **Crawlers kept out** of your numbers and counted separately, so you can see
  what was excluded.
- **26 months of history**, enough to compare this March with last March.

## Where things are

| Where | What |
|---|---|
| **Analytics** in the main nav | All the reports |
| **Settings → Plugins → Craft Analytics** | Tracking, retention, crawlers, emails |
| **Settings → Plugins → Craft Analytics → Goals & funnels** | Conversions (Pro) |
| Any entry's sidebar | That entry's views |
| Dashboard widgets | The overview widget |

## Permissions

Two permissions, under **Settings → Users → Permissions**:

- **View analytics** - can see the reports. Each user sees only the sites they
  can edit content for, unless you also grant *View all sites*.
- **Manage analytics settings** - can create goals and funnels, and change the
  plugin's settings.

## Upgrading to Pro

Buy Pro in the Plugin Store and the extra screens appear. Lite and Pro use the
same tables, so there is no migration to run: your existing data is unchanged
and the Pro reports start filling from the next drain.

## Next

- [How tracking works](tracking-modes.md) - and which mode you want
- [Caching](../configuration/caching.md) - **read this if you use Blitz,
  Cloudflare or Varnish**
- [Your first goal](../reports/goals.md)
