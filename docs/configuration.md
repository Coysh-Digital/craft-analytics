# Configuration reference

Copy `src/config.php` to your project's `config/craft-analytics.php`. Every
setting supports Craft's multi-environment config format and env vars.

| Setting | Default | Notes |
|---|---|---|
| `trackingMode` | `hybrid` | `server` = PHP-side capture only (undercounts behind full-page caches; no dwell/scroll metrics). `client` = beacon only. `hybrid` = both, deduped via a hot-layer nonce. |
| `writeDriver` | `spool` | `spool` appends to Redis/NDJSON and relies on the drain command. `queue` uses a dedicated queue component (worker required). `direct` writes synchronously after the response is flushed — low-traffic sites only. |
| `uniqueCounterDriver` | `auto` | `auto` picks `redis` when a Redis cache/queue is configured, else `hll` (±1.6–0.8% sketches). `exact` suits small sites. |
| `excludePaths` | `[]` | Glob patterns of site paths never tracked. |
| `excludeQueryParams` | `[]` | Query params stripped from tracked URIs. |
| `sessionWindow` | `1800` | Seconds of inactivity before a session closes (60–14400). |
| `saltRotationInterval` | `86400` | Seconds between visitor-hash salt rotations. The 24h default with salt destruction is the basis of the banner-free privacy posture; the privacy panel warns when extended. |
| `saltRotationHour` | `4` | Hour of day (site timezone) rotation aims for, minimising sessions split across the boundary. |
| `hourlyWindowDays` | `7` | Days of hourly-grain rollups kept before lossless compaction to daily rows. |
| `dimensionCap` | `1000` | Per-(site, day, type) cardinality cap; the tail folds into `__other__`. |
| `rollupRetentionMonths` | `26` | Rollup retention (hard cap 26 months). |
| `spoolMaxBytes` | `52428800` | Back-pressure guard: beyond this spool size, oldest data is dropped and a CP warning raised — the site never falls over. |
| `honourGpc` | `true` | Visitors sending `Sec-GPC: 1` are never tracked beyond the anonymous tier. |
| `honourDnt` | `false` | Legacy `DNT: 1` support. |

## A note on multi-day unique visitors

Anonymous (Tier 1) visitor hashes are salted with a key that rotates daily and
is destroyed — the same person cannot be recognised across days, *by design*.
Multi-day "unique visitors" figures are therefore on a **daily-unique basis**
(a visitor returning on three days counts three times) and are labelled as
such in the CP. Consented (Tier 2, Pro) visitors are counted truly across
sessions and days.
