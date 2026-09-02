---
title: How visitors are counted
description: What a "unique visitor" means here, and why the number differs from other analytics tools.
---

# How visitors are counted

Read this before you compare these numbers with another analytics tool, or put
them in a report. The way visitors are counted here differs from most tools,
and the difference is deliberate.

## How visitors are told apart without a cookie

To count visitors you have to tell them apart. Most analytics does that with a
cookie on the device, which is why most analytics needs a consent banner.

Craft Analytics stores nothing on the device. When a request arrives it
computes:

```
visitor = SHA-256(today's secret salt + IP address + user agent + site ID)
```

and keeps the first 8 bytes. The IP address is used for that calculation and
then **discarded** - it is never written to a table, a log, or a cache key.

The salt is a random secret that **rotates every 24 hours**. The old one is
deleted, not archived.

Once it has rotated, there is no way to connect today's hash to yesterday's,
because the information needed to do so no longer exists. That is what lets
the plugin run without a cookie banner.

## The consequence

**Someone who visits on Monday, Wednesday and Friday counts as three unique
visitors, not one.**

On Wednesday there is no way to know they were here on Monday. Keeping the old
salts would make it possible, but that is the tracking the plugin is designed
to avoid.

So when the CP says "unique visitors" over 30 days, it means **the sum of
daily unique visitors**. The screen says so. Any figure you export or query
carries the same caveat.

This is a consequence of the design rather than a bug, but it does mean:

- ❌ Don't compare this number directly with a Google Analytics "users" figure.
  They're measuring different things.
- ✅ Do compare it with itself over time. The trend is entirely sound.
- ✅ Do use **sessions** for "how many visits" - that's exact.
- ✅ Do use **pageviews** for "how much was read" - that's exact.

Plausible works this way for the same reason, as does any analytics tool that
manages without a banner.

## Why the totals differ from GA4, Cloudflare or your server logs

Every tool measures a different slice of the same traffic, so their totals
never line up. It helps to hold three reference points in mind.

**Expect it to read higher than Google Analytics - often much higher.** GA4 is
JavaScript loaded from a Google domain, so ad and tracking blockers stop it
dead, and a consent banner keeps it from running until someone accepts. Both
are common, and on a technical audience the blocked share alone can be a third
to a half of real people. Craft Analytics is served first-party from your own
domain and needs no banner, so it counts the visitors GA4 silently missed.
When you switch across, the numbers usually jump. That jump is real people, not
inflation - it is the traffic GA4 was never seeing.

**Expect it to read lower than Cloudflare, a CDN dashboard or raw server
logs.** Those count every request that reaches the edge, keyed by IP, which
means crawlers, scrapers, AI bots, uptime monitors and security scanners are
all in the figure - and on any public site that automated traffic is a large
slice, often several times the human total. Craft Analytics filters bots (a
maintained crawler list plus heuristics for automation that doesn't announce
itself), so it sits well below an edge or log count. A Cloudflare "unique
visitors" number three to five times larger than what you see here is normal,
and is mostly the bots this plugin is leaving out.

So the honest human total sits **between** the two: above GA4, which drops
blocked and unconsented people, and below the edge count, which adds every bot.
That is the number this plugin aims for.

If a figure ever looks too high to believe, two quick checks settle it:

- ✅ **Pageviews per session.** Humans run about 1.5 to 3; a value near 1.0
  across the board is the signature of bots landing on one page and leaving.
- ✅ **The top pages.** They should be real content. If the busiest paths are
  probes like `wp-login.php` or `.env`, non-human traffic is leaking through;
  if they're your actual pages, the traffic is sound. A CDN's own bot breakdown
  reconciles the rest.

## "Unique visitors" is also an estimate

Separately from the above: the unique count is computed with HyperLogLog, a
sketching algorithm that counts distinct things in a couple of kilobytes
instead of storing every value.

The trade is about **±1.6%** accuracy, in exchange for
[storage](../configuration/retention.md) that does not grow with traffic.
Counting exactly would mean a row per visitor per day per page; the sketch is
33 bytes whether it counted 10 visitors or 10,000.

Below about 100 uniques the count is exact, because at that size the sketch
still stores them individually. A page with 40 visitors reports 40.

The CP labels the accuracy on every screen showing uniques. Exports and the
GraphQL API state it too, so nobody downstream can mistake an estimate for a
count.

If you want exact uniques and have a small site, set `uniqueCounterDriver` to
`exact`. It stores one row per visitor per day. It is still day-scoped -
nothing can fix that, see above.

## What's actually stored

Here is everything the plugin keeps about a pageview:

| Stored | Not stored |
|---|---|
| The path (campaign parameters stripped) | The IP address, in any form |
| The date, and the hour for a week | The full user agent |
| Views, entrances, exits, bounces | Any per-visitor row |
| A 33-byte sketch of who was here today | Anything that survives the salt rotating |
| Browser, OS, device type - as counts | A name, an email, an account |

There is no per-visitor record to anonymise, because none is created. The rows
are counts.

## What this means for DSARs

If someone emails asking what you hold about them: for ordinary
(non-consented) tracking, the answer is **nothing**. There is no record keyed
to them, and no way to find one if there were, because the salt that made
their hash was destroyed.

This meets the definition of anonymous data under GDPR, which puts it outside
the regulation's scope.

The exception is the Pro **journeys** layer, which stores per-visitor rows for
visitors who affirmatively consented. It's off by default, and it comes with
export and erase commands, because that data *is* personal data and you have
obligations about it. See [Privacy & compliance](README.md).

## Sessions

A session is a visit: pageviews from one visitor with no more than 30 minutes
of inactivity between them. Adjustable, but 30 minutes is the industry norm
and matching it makes your numbers comparable to everyone else's.

Sessions live in your cache, never in the database. When one goes quiet the
drain folds its numbers into the daily totals and deletes it. So bounce rate,
session length and entry/exit pages are all real - and the database still only
ever sees aggregates.

One footnote: a visitor who's active across the salt rotation becomes two
sessions, because their hash changed underneath them. Rotation is scheduled
for your site's quietest hour (4am by default) to keep that rare.
