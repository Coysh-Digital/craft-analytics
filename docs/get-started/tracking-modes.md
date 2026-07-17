---
title: How tracking works
description: The three tracking modes, the three write drivers, and how to pick.
---

# How tracking works

Two settings decide how a pageview gets from someone's browser into your
reports. The defaults suit almost everyone, so if you are in a hurry, leave
them and read about [caching](../configuration/caching.md) instead.

## Tracking modes

There are two ways to notice a pageview, and each misses something the other
catches.

### Hybrid (the default)

Both. PHP counts the view while building the page; the tracker script reports
time on page and scroll depth, and picks up any view PHP didn't see - which is
every view served from a cache. They're kept from double-counting by a
one-time nonce, explained in [Static & edge
caching](../configuration/caching.md).

**Costs you:** a 1.2 KB script on the page, and one small cache entry per view
for half an hour.

**Use this unless you have a specific reason not to.**

### Server-side only

PHP counts the view. No JavaScript is added to your pages at all.

**Costs you:** everything a cache serves. A page served by Blitz, Cloudflare or
Varnish never reaches PHP, so it is never counted and your reports become a
fraction of reality. Time on page, scroll depth and bounce refinement are also
unavailable, because nothing on the server knows when someone left. The reports
say "requires hybrid or client mode" rather than showing a zero.

**Use it if** you cache nothing and want no JavaScript on the page. Do not use
it together with a cache.

### Client beacon only

The tracker reports everything. Caching is irrelevant.

**Costs you:** everyone with JavaScript off or a content blocker installed.
That is a small share, and not a random one: it skews towards a technical
audience.

**Use it if** your site is fully static or edge-rendered and PHP does not run
for page requests.

## Write drivers

Once a pageview is noticed, it has to get into the database. All of this
happens **after the page has been sent to the visitor**, so none of these
options make your site slower for the person browsing it. What changes is what
happens under load.

### Spool (the default)

One line appended to a file - about 40 microseconds. Every few minutes the
drain reads the file, adds everything up in memory, and writes the totals in a
single transaction.

A thousand pageviews become a handful of database writes rather than a
thousand. **Use this.**

Needs the drain on cron:

```
*/5 * * * * php craft craft-analytics/drain/run
```

### Queue

The pageview is pushed onto Craft's queue for a worker to pick up.

Use this when your web servers have **no shared writable disk**: containers, or
a load-balanced setup where each machine would have its own spool file. It needs
a queue worker that actually runs, rather than Craft's default of running the
queue on web requests, which would put the work back onto the request you were
trying to keep fast.

### Direct

Written to the database there and then. No cron needed.

It is the simplest to set up and the first to fall over: every pageview is a
database write, and two views in the same instant can race. Fine for a brochure
site with a hundred visits a day, unsuitable for anything busier.

## What about performance?

The work happens after the response has been flushed to the browser, using
`fastcgi_finish_request()` under PHP-FPM. The visitor's page is already
delivered and their browser is already rendering it before any of this starts.

Measured on the dev harness: **-0.46 ms** difference in time-to-first-byte
with the plugin on versus off, which is noise - and 42 microseconds at the
99th percentile for the capture itself.

Under Apache with mod_php there is no `fastcgi_finish_request()`, so the
content is flushed but the connection may not close until the script ends. The
capture work is small enough (1-2 ms) that this rarely matters, but it is not
zero in the way it is under PHP-FPM.

## Crawlers

Googlebot is not a visitor. By default crawlers are kept out of every report
and counted separately on **Analytics → Crawlers**, which is also where you can
see why your server logs report 4,000 requests while your analytics reports
400.

Without filtering, a site with 400 real visitors and 3,000 bot requests reports
3,400, and every number on every screen is wrong in a way that still looks
plausible.

You can turn filtering off in the settings, but there is rarely a reason to.
