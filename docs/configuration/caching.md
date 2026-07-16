---
title: Static & edge caching
description: How Craft Analytics counts pageviews behind Blitz, Cloudflare, Varnish and friends - and why it doesn't need you to configure anything.
---

# Static & edge caching

If you run Blitz, or put Cloudflare in front of your site, or use Varnish, or
host on a platform that serves static HTML from the edge - this page is worth
five minutes. The short version is that it works, and you don't need to
configure anything. But it's worth understanding *why*, because the failure
mode of getting this wrong is numbers that look completely plausible and are
quietly wrong by half.

## The problem, in one paragraph

Caching exists to stop PHP running. That's the whole point: the first visitor
makes PHP build the page, the result is stored, and the next thousand visitors
get the stored copy without Craft, or your plugins, or your templates being
involved at all. Which is wonderful for your server and fatal for any
analytics that counts pageviews in PHP - because PHP doesn't run, so it can't
count anything. Your cache hit rate becomes your under-count rate. A site with
a 90% hit rate reports one visitor in ten.

This isn't hypothetical, and it isn't specific to this plugin. Any
"server-side analytics" behind a cache has this problem.

## How Craft Analytics deals with it

Two ways of noticing a pageview, and hybrid mode uses both:

**PHP counts it** when PHP builds the page. Reliable, invisible, and counts
people with JavaScript disabled - but blind to anything served from a cache.

**A small script counts it** when the page loads in the browser. It doesn't
care where the HTML came from: PHP, Blitz, a CDN in Frankfurt, the browser's
own back button. It runs either way.

The trick is making sure they don't both count the same view. That's what the
nonce does.

## The nonce, and why it survives caching

When PHP renders a page, it puts a one-time value - a nonce - into the tracker
script tag:

```html
<script src="/cpresources/…/tracker.js" defer
        data-endpoint="https://example.com/_ca/collect"
        data-nonce="6a2a1352d5dcc28f"></script>
```

and records that nonce in the cache as "unclaimed". When you leave the page,
the tracker posts the nonce back. The endpoint looks it up:

- **Nonce found, unclaimed** → PHP already counted this view. Claim the nonce
  and record only the time-on-page. Don't count it again.
- **Nonce not found, or already claimed** → nobody counted this view. Count it.

Now watch what happens when Blitz caches the page. The nonce gets baked into
the stored HTML, so **every visitor to that cached page gets the same nonce**.
That sounds like a bug. It's the mechanism:

| | What the visitor gets | What happens |
|---|---|---|
| **Visitor 1** | PHP builds the page, nonce `abc` | PHP counts them. Their beacon claims `abc`, adds dwell time, doesn't double count. |
| **Visitor 2** | Cached HTML, nonce `abc` | PHP never ran. Beacon sends `abc`, which is already claimed → **counted from the beacon**. |
| **Visitor 3** | Cached HTML, nonce `abc` | Same → **counted**. |
| **Visitor 900** | Cached HTML, nonce `abc` | Same → **counted**. |

Three visitors, three views. Nine hundred visitors, nine hundred views. No
cache configuration, no cache-busting, no exclusion rules, no integration
plugin. The first claim is the only one that finds an unclaimed nonce, and
everyone after it is counted.

This works with **any** cache - Blitz, Cloudflare, Fastly, Varnish, nginx
`proxy_cache`, a static export on Netlify. The plugin doesn't detect them or
know their names. It doesn't need to.

## Blitz specifically

Blitz has two ways of serving its cache, and both are fine:

**Served by the web server.** You've added the nginx or Apache rewrite from
Blitz's docs, so cached pages are served as files and PHP genuinely never
starts. The beacon counts them, exactly as described above.

**Served by Blitz through PHP.** The default without a rewrite. PHP starts,
Blitz recognises a cache hit, sends the stored HTML and exits early - your
templates never run. Craft Analytics spots this (if PHP had rendered the page
there would be a fresh nonce, and there isn't) and stands aside so the beacon
counts it, exactly as in the other case.

The point of handling both: **your numbers don't change when you add or remove
the rewrite.** That's a deployment detail and it has no business moving your
traffic figures.

::: tip
Excluding the beacon endpoint from your cache is a good idea - it's a POST, so
most caches ignore it anyway, but being explicit costs nothing:

```php
// config/blitz.php
'excludedUriPatterns' => [
    ['siteId' => '', 'uriPattern' => '_ca/.*'],
],
```
:::

## Cloudflare and other edge caches

Nothing to do. The tracker posts to a path on **your own domain**, so:

- The request goes to your origin, not to some analytics vendor.
- Ad blockers have nothing to block - there's no third-party domain in the
  request, because there's no third party.
- Your visitors' browsers make exactly one extra request per pageview, to you.

If you cache aggressively at the edge, make sure your beacon path (default
`_ca/collect`) is passed through to the origin rather than cached. A cached
`204 No Content` response is harmless but pointless.

## What if I turn hybrid off?

Then you're choosing one of the two halves, and you should know what you're
giving up.

**Server-side only** behind a cache **will under-count, badly, and silently**.
The reports will look fine. They will just be a fraction of reality, and the
fraction is your cache miss rate. If you run a cache and pick this mode, the
settings screen warns you, and the warning is not decorative.

There is one honest use for server-only mode: you don't cache anything, and
you want zero JavaScript on the page. Then it's the right answer, and you
accept that time-on-page and scroll depth are impossible because nothing on
the server knows when someone left.

**Client beacon only** is immune to caching but misses anyone with JavaScript
off or a blocker installed. That's a small but real share of people, and it
skews toward the technical end of your audience - which matters if that's who
you're writing for.

Hybrid is the default because it's the only mode that's correct behind a cache
*and* counts people who block scripts.

## Known edges

Being straight with you about the rough bits:

**The generating visitor's nonce can be claimed by someone else.** If visitor
1 generates the page and leaves so fast that their beacon never fires, visitor
2's beacon claims the unclaimed nonce and isn't counted - visitor 1 was
counted server-side, so you get one view for two people. This happens once per
cache generation at most, so on a page cached for an hour and viewed 900
times, the worst case is 899 instead of 900. We think that's a fine trade for
needing no cache integration at all.

**Nonces expire.** They live for `nonceTtl` (default 1800 seconds, 30
minutes). If somebody opens a page and leaves it in a tab for two hours before
closing it, its nonce is long gone, and their beacon counts the view a second
time. Raising `nonceTtl` shrinks the window at the cost of one small cache
entry per view living longer.

**A cached page and a browser back-button page look the same.** Both were
never rendered by PHP for that visitor, and both get counted by the beacon.
That's correct - they did view the page - but it's worth knowing that the
back-forward cache counts as a view here and doesn't in some other tools.

## Checking it yourself

Don't take our word for it. Prove it on your own site:

1. Clear your cache: `php craft blitz/cache/clear`
2. Visit a page in a normal browser.
3. Visit the same page from a different device or browser (a different user
   agent matters - see below).
4. Run `php craft craft-analytics/drain/run`
5. Look at **Analytics → Pages**. Two views.

::: warning
If you test with `curl`, you'll get zero views and think it's broken. Two
reasons, both deliberate: `curl` sends no `Accept-Language` header, which is
one of the signals used to spot bots, and it runs no JavaScript, so no beacon
is ever sent. Test with a real browser.

Two tabs on the same machine also count as **one** visitor, not two - same IP,
same user agent, so as far as the plugin can tell you are one person, because
you are.
:::
