---
title: How visitors are counted
description: What a "unique visitor" means here, why it isn't what you're used to, and why that's the point.
---

# How visitors are counted

This page explains the one thing about Craft Analytics that will surprise you,
and it's better to be surprised now than in a board meeting.

## No cookies. No banner. Here's the trick.

To count visitors you need to tell them apart. Most analytics does that by
putting a cookie on the device - a permanent name tag - which is why most
analytics needs a consent banner.

Craft Analytics doesn't store anything on the device. Instead, when a request
arrives, it computes:

```
visitor = SHA-256(today's secret salt + IP address + user agent + site ID)
```

and keeps the first 8 bytes. The IP address is used for that calculation and
then **discarded** - it is never written to a table, a log, or a cache key.

The salt is a random secret that **rotates every 24 hours**, and the old one is
destroyed. Not archived. Destroyed.

That means: after rotation, there is no way to connect today's hash to
yesterday's. Not a slow way, not an expensive way, not a way with a court
order. The information no longer exists anywhere.

That's what lets you run this without a cookie banner, and it's the whole
design.

## The consequence

**Someone who visits on Monday, Wednesday and Friday counts as three unique
visitors, not one.**

Because on Wednesday there is genuinely no way to know they were here on
Monday. We could pretend otherwise; we'd have to keep the old salts to do it,
which is exactly the tracking we're not doing.

So when the CP says "unique visitors" over 30 days, it means **the sum of
daily unique visitors**. The screen says so. Any figure you export or query
carries the same caveat.

This isn't a limitation we're apologising for - it's the property you're
buying. But it means:

- ❌ Don't compare this number directly with a Google Analytics "users" figure.
  They're measuring different things.
- ✅ Do compare it with itself over time. The trend is entirely sound.
- ✅ Do use **sessions** for "how many visits" - that's exact.
- ✅ Do use **pageviews** for "how much was read" - that's exact.

Plausible has the same property for the same reason. So does any analytics
tool that manages without a banner.

## "Unique visitors" is also an estimate

Separately from the above: the unique count is computed with HyperLogLog, a
sketching algorithm that counts distinct things in a couple of kilobytes
instead of storing every value.

The trade is about **±1.6%** accuracy, and the reason for it is
[storage](../configuration/retention.md): storing every visitor hash so you
could count them exactly means a row per visitor per day per page, and your
database grows with your traffic forever. The sketch is 33 bytes and doesn't
care whether it counted 10 visitors or 10,000.

Under about 100 uniques the count is exact anyway, because at that size the
sketch is still storing them individually. So a page with 40 visitors reports
40, not "about 40".

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

And that's it. Not "we anonymise it" - there is no per-visitor record to
anonymise. The rows are counts.

## What this means for DSARs

If someone emails asking what you hold about them: for ordinary
(non-consented) tracking, the answer is **nothing**. There is no record keyed
to them, and no way to find one if there were, because the salt that made
their hash was destroyed.

That's not a dodge - it's the definition of anonymous data under GDPR, and
anonymous data is outside its scope entirely.

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
