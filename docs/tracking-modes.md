# Tracking modes and the beacon

Server-side capture is free and invisible, but it can only see requests PHP
handled. The beacon exists to cover exactly what it can't.

## What each mode can and cannot do

| | `server` | `hybrid` (default) | `client` |
|---|---|---|---|
| Pageviews | ✅ | ✅ | ✅ (on exit) |
| Pages served by a full-page cache | ❌ **silently under-counts** | ✅ | ✅ |
| Back/forward-cache views | ❌ | ✅ | ✅ |
| Time on page | ❌ impossible | ✅ | ✅ |
| Works with JS disabled | ✅ | ✅ (partially) | ❌ |
| Requests added per pageview | 0 | 1 | 1 |

`server` mode's weakness is the dangerous kind: behind Blitz, Varnish,
Cloudflare or Fastly, PHP never runs for a cached page, so the view is never
counted — and the numbers still look plausible. The CP warns when a
caching plugin is installed and the mode is `server`.

In `client` mode a pageview isn't recorded until the visitor leaves the page,
so real-time visitors will under-report. `hybrid` doesn't have this problem
because the server counts the view immediately.

## How hybrid avoids double-counting

The obvious approach — "the beacon skips counting if the server already did" —
breaks the moment a page is cached, because the flag gets baked into the
cached HTML and served to everyone.

So the nonce is a **one-time claim**, not a flag:

1. PHP renders the page, counts the view, embeds a random nonce, and records
   that nonce server-side (after the response is flushed — the visitor never
   waits for it).
2. The beacon sends the nonce when the visitor leaves.
3. The endpoint tries to **claim** it. Claimed successfully → the server
   already counted this view → record dwell only. Claim fails → count the
   view.

A claim fails in exactly the cases where it should:

- **Cached page, first visitor after caching** — nonce was recorded, claimed
  once, gone. Every later visitor of that cached HTML sends the same
  already-claimed nonce → counted.
- **Page cached before we ever saw it** — nonce was never recorded → counted.
- **bfcache restore** — the tracker drops the nonce, since it belonged to the
  original delivery → counted.

No cache integration, no configuration, no cache-plugin API. It self-heals.

Verified end-to-end in a real browser: a page restored from the browser's HTTP
cache (PHP never ran) was counted from its beacon, while a freshly rendered
page counted exactly once and its beacon contributed only dwell time.

### The one edge case

Nonces are kept for `nonceTtl` (default 1800s, matching the session window).
If someone leaves a page open longer than that and *then* leaves, the nonce
has expired and the endpoint cannot tell "stale fresh page" from "cached
page" — so it counts, and that view is counted twice. Raise `nonceTtl` if
long-lived tabs matter to you; the cost is one small cache entry per
server-counted pageview for that long. Redis is recommended for busy sites.

## The tracker script

- **1,172 bytes gzipped** (budget: 2,048 — enforced by CI, which fails on
  regression).
- Zero dependencies, deferred, and it renders nothing, so it cannot shift
  layout or block the page.
- **Nothing on the device**: no cookies, no `localStorage`, no
  `sessionStorage`, no identifiers. Verified in a browser, not just asserted.
- Sends exactly one request per pageview, on `pagehide`/`visibilitychange`.
- Degrades to nothing without JS or `sendBeacon`.
- Served from your own domain (never a CDN), published by Craft's asset
  manager and cacheable.

Dwell time measures until the page is first hidden. If a visitor switches tabs
and comes back, the extra time isn't added — the alternative is more requests
per pageview, and accurate counts matter more than a perfect dwell figure.

Place it yourself by turning off `injectScript`.

## The endpoint

`POST` to `beaconPath` (default `/_ca/collect`), returning `204` for
everything — including requests it ignores, so it never tells a fingerprinter
what it rejected.

It applies the same gates as server-side capture: bot filtering, Global
Privacy Control, excluded paths, and per-visitor rate limiting (keyed on the
salted visitor hash — there's no IP to key on).

Being anonymous and CSRF-exempt is unavoidable: the posting page may have come
from a cache with no session and no token. That makes the endpoint forgeable —
anyone can post fabricated pageviews. Every analytics endpoint that works
behind a cache shares this; the blast radius is skewed numbers, never stored
personal data.
