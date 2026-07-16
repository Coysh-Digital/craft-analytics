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

That's the plugin installed. It is not yet collecting anything useful, because
of one more step.

## The one step people forget

**Put the drain on your cron.** Without it, the plugin records pageviews and
never adds them up, so your reports stay resolutely empty and you conclude the
plugin is broken.

```
*/5 * * * * /usr/bin/php /path/to/your/site/craft craft-analytics/drain/run
```

Every five minutes is a good default. It means the numbers are at most five
minutes behind, which nobody has ever complained about, and it keeps each run
small. Running it every minute is fine too.

While you're in there, add the housekeeping job:

```
0 4 * * * /usr/bin/php /path/to/your/site/craft craft-analytics/gc/run
```

That one compacts old hourly rows into daily ones and deletes anything past
your retention period. Once a day, at a quiet hour, is plenty.

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

If the drain says it drained 0 hits, jump to
[Troubleshooting](../troubleshooting.md) - there's a short list of the usual
suspects, and "you were testing with curl" is on it.

## What you get out of the box

Nothing to configure. The defaults are the ones you'd pick anyway:

- **Hybrid tracking**, which is the only mode that's correct behind a cache
  and still counts people who block scripts.
- **No cookies**, no local storage, no device identifiers. Nothing to consent
  to, so there's nothing to put a banner in front of.
- **No IP addresses stored**, anywhere, ever - not in a table, not in a log.
- **Crawlers kept out** of your numbers and counted separately, so you can see
  what was excluded.
- **26 months of history**, which is enough to compare this March with last
  March.

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

- **View analytics** - can see the reports. Each user only sees the sites they
  can edit content for, unless you also grant *View all sites*.
- **Manage analytics settings** - can create goals and funnels, and change the
  plugin settings.

## Upgrading to Pro

Buy Pro in the Plugin Store and the extra screens turn on. There's no
migration and no data conversion - Lite and Pro use the same tables, so
everything you've already collected is still there, and the Pro reports start
filling from the next drain.

## Next

- [How tracking works](tracking-modes.md) - and which mode you want
- [Caching](../configuration/caching.md) - **read this if you use Blitz,
  Cloudflare or Varnish**
- [Your first goal](../reports/goals.md)
