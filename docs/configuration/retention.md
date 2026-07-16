# Storage and counting

The claim is that database growth tracks *cardinality × time*, never traffic
volume. This is how that works, and where the edges are.

## Rollups, not hits

A pageview never becomes a row. Web requests append a line to a spool; the
drain collapses a batch in memory and upserts rollup rows keyed on
`(siteId, date, hour, …dimensionIds)`.

Measured in the dev harness: **50 pageviews from 10 visitors produced 1 rollup
row** with a 33-byte sketch. The thousandth view of a page in an hour updates
the same row as the first.

Dimension *values* (paths, referrer hosts, browsers) live once in a shared
`dimensions` table; rollups store integer foreign keys. That bounds index size
as well as row count.

## Unique visitors

Counting distinct visitors normally means keeping a row per visitor. Instead
each rollup row carries a **HyperLogLog sketch** - a fixed-size structure that
estimates cardinality and, crucially, **merges**.

Merging is what makes a date range answerable:

> Uniques over 30 days is the **union** of the daily sketches, never their sum.
> Summing daily uniques counts a returning visitor once per day. Tools that do
> this are overstating their numbers, and this plugin will not.

| Driver | Accuracy | Storage | When |
|---|---|---|---|
| `redis` | ±0.8% | ~12 KB per counter, in Redis | Default when Redis is configured - native `PFADD`/`PFCOUNT`/`PFMERGE`, effectively free |
| `hll` | ±1.6% (p=12) / ±0.8% (p=14) | Sparse: ~30–400 bytes. Dense: 4 KB (p=12) / 16 KB (p=14) | Default fallback; needs no infrastructure |
| `exact` | exact (within a day) | One row per (scope, visitor) per day | Small sites that want exact figures |

The `hll` driver starts every sketch **sparse** (a map of touched registers)
and promotes to dense only when that stops being smaller. A page with 40
visitors in an hour costs ~120 bytes, not 4 KB - which is why sketch storage
also doesn't track traffic.

Precision is configurable (`hllPrecision`, 11–14). The CP reports the driver's
accuracy rather than presenting an estimate as a count.

### The daily-unique basis (important)

Tier-1 visitor hashes are salted with a key that rotates every 24 hours and is
then destroyed. The same person is deliberately unrecognisable across days.

So multi-day "unique visitors" is on a **daily-unique basis**: someone who
visits on three days counts three times. Merging sketches still dedupes
correctly *within* each day, and across pages/sites/hours - but no algorithm
can recover an identity the salt rotation destroyed on purpose. This is the
cost of not needing a consent banner, and the CP labels it rather than hiding
it. Consented (Tier-2, Pro) visitors are counted truly across days.

## Cardinality capping

Paths and referrer hosts have no natural ceiling - a crawler hitting
`/search?q=<random>` a million times would otherwise mean a million rows.

Each `(site, date, type)` therefore tracks at most `dimensionCap` (default
1,000) distinct values and folds the rest into a single reserved `__other__`
dimension. **Nothing is lost**: the folded views are still counted, just not
attributed individually. In the harness, 20 distinct paths against a cap of 5
produced 6 rows - 5 tracked plus `__other__` holding the remaining 15 views.

**It is first-N, not top-N.** When a value first appears there is no way to
know whether it will end the day popular, so the cap admits the first N
distinct values of the day and folds the rest. A value already admitted stays
tracked for the rest of the day regardless of later pressure. In practice the
cap only engages during a cardinality explosion - a normal site never
approaches 1,000 distinct paths in a day - and in that situation the folded
tail is the junk. We would rather say this than imply a ranking that isn't
there.

Browsers, OSes, device types and channels are naturally bounded and aren't
capped.

## Compaction and retention

- **Hourly → daily.** Days older than `hourlyWindowDays` (default 7) compact
  from 24 rows to 1. Lossless: counters add and sketches merge, so the daily
  uniques figure is the true union, not a sum. Idempotent and safe to
  interrupt.
- **Retention.** Rollups are deleted past `rollupRetentionMonths` (default and
  hard cap 26).
- **Unique membership rows** (`exact` driver) are dropped once the salt that
  produced their hashes is gone - after that they cannot be matched to
  anything, by us or anyone.
- **Orphaned dimensions** - values no rollup references any more - are pruned.

Schedule it; don't rely on Craft's GC alone:

```cron
0 4 * * * php craft craft-analytics/gc/run
```

## What is stored, exactly

Per rollup row: integers, a date, an hour, dimension foreign keys, and
optionally a sketch. No IP address, in any form, ever. No raw hit rows. No
per-visitor rows outside the `exact` driver's day-scoped membership table and
the Pro, opt-in, consented journeys layer (phase 6).
