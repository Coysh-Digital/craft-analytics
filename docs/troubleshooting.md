---
title: Troubleshooting
description: Empty reports, numbers that look wrong, and goals stuck at zero.
---

# Troubleshooting

## The reports are empty

**Is the drain running?** This is the answer nine times out of ten.

```bash
php craft craft-analytics/drain/run
```

If that prints `Drained 12 hit(s)...` and your reports fill up, your cron
isn't running the drain. Fix the cron:

```
*/5 * * * * /usr/bin/php /path/to/site/craft craft-analytics/drain/run
```

Use the full path to PHP. Cron's `PATH` is not your `PATH`, and this is the
most common way that line silently does nothing.

**Is anything being captured?** Look at the spool:

```bash
ls -la storage/runtime/craft-analytics/spool/
```

A `spool.ndjson` that grows when you load a page means capture is fine and the
problem is the drain. An empty directory means nothing is being captured -
read on.

## Nothing is being captured

**Are you testing with curl?** That would explain it. `curl` sends no
`Accept-Language` header, which is one of the signals used to identify bots,
so the request is ignored. It also runs no JavaScript, so no beacon is sent.
Use a real browser.

If you must use curl:

```bash
curl -H "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36" \
     -H "Accept-Language: en-GB,en;q=0.9" \
     https://example.com/
```

**Are you testing with headless Chrome?** Its user agent says
`HeadlessChrome`, and its `sec-ch-ua` header says so too even if you override
the UA string. Both are bot signals. Set a real user agent *and* a matching
`sec-ch-ua`.

**Is it a bot?** Check **Analytics → Crawlers**. If your test requests are
showing up there, the filter is doing its job and your client looks like a
bot to it.

**Are you looking at the control panel?** CP requests are never tracked.
Neither are previews, tokenised requests, non-200 responses, or anything
that isn't HTML.

## The numbers look too low

**Do you run a cache?** In server-only mode, cached pages are never counted,
because PHP never runs. Your under-count is your cache hit rate - so a 90% hit
rate means you're seeing one visitor in ten.

**Switch to hybrid**, which handles this with no cache configuration. See
[Static & edge caching](configuration/caching.md).

**Is `blockCrawlers` doing more than you expect?** Check **Analytics →
Crawlers** to see what's being excluded. If something you consider real
traffic is on that list, it looks like a bot.

## The numbers look too high

**Are crawlers being counted?** If you turned `blockCrawlers` off, they are,
and they're inside every number on every screen. Turn it back on.

## "Unique visitors" doesn't match Google Analytics

It will not match. Craft Analytics counts on a **daily-unique** basis: someone
visiting on three days counts three times, because the hashing salt rotates
every 24 hours and removes the link between their days.

There is no setting to change this. Changing it would mean keeping the old
salts, which is the tracking the plugin avoids in order to work without a
cookie banner.

[How visitors are counted](privacy/how-counting-works.md) explains this in
full, and is worth reading before comparing the two tools.

## A goal is stuck at zero

**Check the target.** By type:

- **Page visit** - must start with `/`. Query strings are ignored, so
  `/thank-you` matches `/thank-you?ref=email`. Use `*` for wildcards.
- **Event** - must exactly match the name in `craftAnalytics.event('…')`. A
  *page* called `/signup` will not match an *event* goal called `signup`.
- **Entry visit** - the entry's ID, not its slug.
- **Duration / scroll** - need hybrid or client mode. The server cannot know
  either.

**Did you add the goal after the traffic?** Goals aren't retrospective and
can't be - the pageviews they'd have matched are already aggregated and the
individual visits are gone. Make the goal before the campaign.

**Has the session ended?** Duration and scroll goals are only decided when a
session goes idle, which takes 30 minutes by default. You're not waiting for a
bug.

## A funnel step is stuck at zero

Check its goal on the Goals screen first - a funnel can only be as good as the
goals under it.

If the goal is converting but the funnel step isn't, it's **order**. A session
only counts at step 3 if it did step 1, then step 2, then step 3. Someone who
hit your checkout page before your basket page did not walk that funnel.
[More on this](reports/funnels.md).

## Views appear twice

This should not happen. Things to check first:

- **Is `nonceTtl` shorter than people's attention spans?** A page left open
  longer than `nonceTtl` (default 30 minutes) loses its nonce, and the beacon
  counts the view again. Raise it.
- **Are you injecting the tracker twice?** If `injectScript` is on *and*
  you've also placed the script by hand, you'll get two beacons.

## Sessions look wrong around 4am

The salt rotates at 4am (your site's timezone, configurable). A visitor active
across that instant becomes two sessions, because their hash changed. It's
scheduled for your quietest hour for exactly this reason, and it's a known,
accepted trade for the 24-hour rotation.

## Still stuck?

```bash
php craft craft-analytics/info
```

prints the edition and the effective settings, which is usually enough to spot
that staging is running a config you forgot about.
