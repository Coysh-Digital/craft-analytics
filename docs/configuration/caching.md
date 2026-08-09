---
title: Static & edge caching
description: How Craft Analytics counts pageviews behind Blitz, Cloudflare and Varnish, without any cache configuration.
---

# Static & edge caching

Read this if you run Blitz, Cloudflare, Varnish, or anything else that serves
your HTML without PHP. Tracking works behind all of them and needs no
configuration, but it is worth knowing how, because getting it wrong produces
numbers that look reasonable and are half what they should be.

## The problem

Caching works by not running PHP. The first visitor causes the page to be
built, the result is stored, and the next thousand visitors get the stored
copy without Craft or your templates being involved.

That is good for your server and a problem for analytics that counts
pageviews in PHP, because PHP never runs for those visitors. Your cache hit
rate becomes your under-count rate: at a 90% hit rate you would see one
visitor in ten.

This affects any server-side analytics behind a cache, not just this one.

## How Craft Analytics deals with it

There are two ways to notice a pageview, and hybrid mode uses both.

**PHP counts it** while building the page. This adds nothing to the page and
counts people with JavaScript disabled, but it cannot see anything served from
a cache.

**A small script counts it** when the page loads in the browser. It works
regardless of where the HTML came from: PHP, Blitz, a CDN, or the browser's
back button. It reports the view as the page loads, not when the visitor
leaves, so a cached page is counted while they are still reading it - which is
also what lets Real-time show them. A second, smaller request goes as they
leave, carrying time on page and scroll depth. It can never count a view.

The nonce is what stops the two counting the same view twice.

## The nonce, and why it survives caching

When PHP renders a page, it puts a one-time value - a nonce - into the tracker
script tag:

```html
<script src="/cpresources/…/tracker.js" defer
        data-endpoint="https://example.com/_ca/collect"
        data-nonce="6a2a1352d5dcc28f"></script>
```

and records, against **that nonce and that visitor**, that their view is
already counted. When the page loads, the tracker posts the nonce back and the
endpoint looks it up:

- **A record for this nonce and this visitor** → PHP already counted them.
  Claim it and count nothing.
- **No record** → nobody counted this view. Count it.

When Blitz caches the page, the nonce is stored in the HTML along with
everything else, so **every visitor to that cached page receives the same
nonce**. Because the record is keyed on the visitor as well, that does not
matter:

| | What the visitor gets | What happens |
|---|---|---|
| **Visitor 1** | PHP builds the page, nonce `abc` | PHP counts them, and records `abc` for visitor 1. Their beacon claims it and counts nothing. |
| **Visitor 2** | Cached HTML, nonce `abc` | PHP never counted them. No record for `abc` + visitor 2 → **counted from the beacon**. |
| **Visitor 3** | Cached HTML, nonce `abc` | Same → **counted**. |
| **Visitor 900** | Cached HTML, nonce `abc` | Same → **counted**. |

Three visitors give three views; nine hundred give nine hundred. There is no
cache configuration, cache-busting or exclusion rule to set up.

Keying the record on the visitor as well as the nonce matters more than it
looks. Keyed on the nonce alone, whichever visitor claimed it first was
mistaken for the one PHP rendered for, and their own view vanished - and if the
cache was warmed by something without JavaScript, such as a warming crawler,
nobody ever claimed it and the first real visitor vanished instead.

This works with any cache - Blitz, Cloudflare, Fastly, Varnish, nginx
`proxy_cache`, a static export on Netlify - because the plugin does not detect
or depend on which one you use.

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

Both are handled so that **your numbers do not change when you add or remove
the rewrite**, which is a deployment detail rather than something that should
affect your traffic figures.

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

- The request goes to your origin rather than to an analytics vendor.
- There is no third-party domain for an ad blocker to block.
- Each pageview costs one extra request, to your own server.

If you cache aggressively at the edge, make sure your beacon path (default
`_ca/collect`) is passed through to the origin rather than cached. A cached
`204 No Content` response is harmless but pointless.

## What if I turn hybrid off?

Then you are choosing one of the two halves, and giving up what the other one
catches.

**Server-side only** behind a cache will under-count without any sign that it
is doing so. The reports look normal; they are simply a fraction of reality,
and the fraction is your cache miss rate. The settings screen warns you if you
choose this mode with a caching plugin installed.

Server-only makes sense if you cache nothing and want no JavaScript on the
page. In that case time-on-page and scroll depth are unavailable, because
nothing on the server knows when someone left.

**Client beacon only** is unaffected by caching but misses anyone with
JavaScript off or a content blocker installed. That is a small share of
people, but not a random one: it skews towards a more technical audience.

Hybrid is the default because it is the only mode that is accurate behind a
cache and still counts people who block scripts.

## Known edges

Some cases worth knowing about:

**A cached page carries no referrer.** The referrer that feeds the Sources
report is read from the request PHP handled; the beacon deliberately does not
send one, because a value supplied by the browser is a value anyone can forge.
So a session whose entry page came from the cache lands under **Direct** even
if the visitor arrived from a search engine or another site. The higher your
cache hit rate, the more of your traffic this applies to.

Campaigns are the exception, and tagging your inbound links is the way to keep
source data behind an aggressive cache: UTM parameters live in the URL the
visitor actually requested, so the beacon sends them and they are read there.
A forgeable referrer and a tag in the requested URL are not the same kind of
evidence, which is why one is trusted and the other is not.

Here is the whole picture for a page served from a cache:

| What                          | Behind a full-page cache                    |
| ----------------------------- | ------------------------------------------- |
| Pageviews                     | Counted, by the beacon                      |
| Sessions, bounce, duration    | Counted                                     |
| Unique visitors               | Counted                                     |
| Time on page, scroll depth    | Counted, by the engagement beacon           |
| Campaigns (UTM)               | Counted - they travel in the URL            |
| Referrer / Sources            | **Lost** - reported as Direct               |
| Entry-type, section and author reports | **Reduced** - see below            |
| Consented journeys (Pro)      | **Lost** - the beacon resolves no visitor   |

The content reports depend on knowing which entry a path belongs to, and the
beacon sends a path rather than an element. A page whose row is created by a
beacon therefore starts out with no element attached. The next time PHP
renders that same page in the same hour, the row is filled in - so on a site
with any uncached traffic at all this corrects itself, and on a fully cached
one those pages stay absent from the content breakdowns while still counting
in every total.

**A cached page needs JavaScript to be counted.** PHP knows when it built a
page and when it only served a stored copy, and it counts the first and leaves
the second to the beacon. So somebody with JavaScript disabled is counted on a
freshly built page and not on a cached one. Nothing can fix the second case:
on a cache hit the only evidence they were there is the script you have
blocked.

**Nonces expire.** They live for `nonceTtl` (default 1800 seconds). The beacon
now goes on load rather than on the way out, so it arrives milliseconds after
the page is recorded, and an expiry in that gap is very hard to hit.

**A cached page and a browser back-button page look the same.** Neither was
rendered by PHP for that visitor, so both are counted by the beacon. They did
view the page, so this is correct, but note that some other tools do not count
a back-button view.

## Checking it yourself

To confirm it on your own site:

1. Clear your cache: `php craft blitz/cache/clear`
2. Visit a page in a normal browser.
3. Visit the same page from a different device or browser (a different user
   agent matters - see below).
4. Run `php craft craft-analytics/drain/run`
5. Look at **Analytics → Pages**. Two views.

::: warning
Testing with `curl` gives you zero views. `curl` sends no `Accept-Language`
header, which is one of the signals used to identify bots, and it runs no
JavaScript, so no beacon is sent. Use a real browser.

Two tabs on the same machine count as one visitor: same IP, same user agent,
so the plugin sees one person.
:::
