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

That installs the plugin, and it starts counting straight away. To keep the
numbers flowing efficiently, give the drain a cron entry.

## Put the drain on your cron

Pageviews land in a fast spool first; the *drain* reads that spool and adds
them into your reports. On a cron, that happens in the background:

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

::: tip No cron? It still works.
If your host doesn't offer cron, the plugin drains itself: at most once a
minute, an ordinary page request runs the drain — after the visitor already has
their page, so it costs them nothing. This fallback is on by default (**Settings
→ Craft Analytics → How data is written**). It skips a spool that has grown past
2 MB, so a busy site, or one clearing a backlog, still wants a real cron entry.
Leave the fallback on even with cron; it only ever fires in the gap between runs.
:::

::: tip
Not sure hits are being counted? If pageviews have arrived but nothing has
drained them yet, **Analytics → Dashboard** says so with a *"Data is waiting to
be counted"* notice, and tells you how to clear it.
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
| Dashboard widgets | The Analytics overview and Analytics real-time widgets |

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
