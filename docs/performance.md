# Performance

Craft Analytics claims it won't hurt your site. That is a measurable claim, so
it gets measured — see [benchmarks/](../benchmarks/README.md) to reproduce
everything below.

## Why time-to-first-byte is unaffected

Capture is registered on `Response::EVENT_AFTER_SEND`, which Yii fires *after*
the response body has been written to the socket. The handler's first act is
`fastcgi_finish_request()`, which closes the connection to the visitor. Only
then does any analytics work happen. The visitor has the whole page before the
plugin does anything at all — so there is no mechanism by which capture could
add to TTFB.

The one check that *does* run before the flush is "is this request trackable?"
— a handful of boolean tests on data already in memory (request method, status
code, content type, path globs, UA). No queries, no I/O.

### Measured

Median TTFB against the ddev harness, 60 samples per condition, requests posing
as a real browser so the full capture path runs:

| | Median TTFB |
|---|---|
| Plugin enabled | 24.32 ms |
| Plugin disabled | 24.78 ms |
| **Delta** | **−0.46 ms (−1.9%)** |

The delta is negative, which is the expected result: there is no real
difference, and run-to-run noise on a developer machine is larger than
anything the plugin contributes. `benchmarks/ttfb.sh` asserts the capture
actually happened during the run (by checking the spool grew), because a
benchmark that silently measures a no-op is worse than no benchmark.

## What capture actually costs

The FPM worker stays busy for the post-flush work, so this doesn't affect
TTFB but does affect *capacity* at saturation. The budget is ≤ 2 ms of CPU.

Measured over 20,000 hits (`php benchmarks/capture-cost.php 20000`) — visitor
hashing, payload encoding, and the spool append:

| | Per hit |
|---|---|
| mean | 24.4 µs |
| median | 23.3 µs |
| p95 | 34.0 µs |
| p99 | **42.3 µs** (2% of the 2 ms budget) |
| spool cost | 296.8 bytes |

## Why the database doesn't grow with traffic

Web requests never write to the database. They append one line to a spool; the
drain reads the spool, collapses it in memory, and writes rollups.

In the harness, 70 pageviews of one page in one hour drained into **1 bucket**
— one row to upsert, not 70 inserts. That ratio is the whole point: rows track
cardinality × time, never traffic volume.

## Caveats, stated plainly

- **Non-FPM SAPIs.** `fastcgi_finish_request()` doesn't exist under Apache
  mod_php, and there is no true equivalent. The plugin falls back to
  `litespeed_finish_request()` where available, and otherwise simply does the
  work at end of script — at ~42 µs p99, immaterial, but not the same
  structural guarantee.
- **Capacity, not latency.** At sustained saturation the post-flush
  milliseconds occupy a worker that could be serving the next request. The
  spool driver keeps this to tens of microseconds; the `direct` driver does a
  transaction per pageview and does not.
- **`direct` and `queue` drivers** trade the drain's batching away, and with
  it the collapse ratio above. `spool` is the default for real traffic for
  this reason.
- **Laptop numbers.** The TTFB figures come from a ddev container on a
  developer machine, not production hardware. The structural argument (work
  happens after the connection closes) is what generalises; the specific
  milliseconds do not.
