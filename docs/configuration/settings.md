# Configuration reference

Copy `src/config.php` to your project's `config/craft-analytics.php`. Every
setting supports Craft's multi-environment config format and env vars.

| Setting | Default | Notes |
|---|---|---|
| `trackingMode` | `hybrid` | `server` = PHP-side capture only (under-counts behind full-page caches; no time-on-page). `client` = beacon only. `hybrid` = both, deduped by a one-time nonce. See [tracking modes](../get-started/tracking-modes.md). |
| `injectScript` | `true` | Inject the tracker automatically before `</body>` on site pages. |
| `beaconPath` | `_ca/collect` | First-party site path the beacon posts to. |
| `nonceTtl` | `1800` | Seconds a hybrid dedupe nonce stays claimable. Must outlast how long a visitor might sit on a page before leaving, or that view can be counted twice. |
| `beaconRateLimit` | `120` | Maximum beacons accepted per visitor per minute. |
| `writeDriver` | `spool` | `spool` appends to Redis/NDJSON and relies on the drain command. `queue` uses a dedicated queue component (worker required). `direct` writes synchronously after the response is flushed - low-traffic sites only. |
| `uniqueCounterDriver` | `auto` | `auto` picks `redis` when a Redis cache is configured, else `hll`. `exact` suits small sites. See [storage](../configuration/retention.md). |
| `hllPrecision` | `12` | HyperLogLog precision (11–14) for the `hll` driver. 12 = 4 KB dense/±1.6%; 14 = 16 KB/±0.8%. Sparse sketches cost far less. |
| `excludePaths` | `[]` | Glob patterns of site paths never tracked. |
| `excludeQueryParams` | `[]` | Extra query params stripped from tracked URIs. Campaign tags (`utm_*`, `gclid`, `fbclid`, ...), ad-network params (`gad_source`, `gad_campaignid`, `_gl`, `_ga`, `srsltid`, ...) and Craft's preview `token` are always stripped; list any site-specific ones here. |
| `stripQueryString` | `false` | Drop the whole query string from tracked page paths, so every `?ad=`, `?cid=` and similar variant of a page collapses onto one clean path. Attribution is read first, so UTM reports are unaffected, and site search still works because it is read separately. |
| `sessionWindow` | `1800` | Seconds of inactivity before a session closes (60–14400). |
| `saltRotationInterval` | `86400` | Seconds between visitor-hash salt rotations. The 24h default with salt destruction is the basis of the banner-free privacy posture; the privacy panel warns when extended. |
| `saltRotationHour` | `4` | Hour of day (site timezone) rotation aims for, minimising sessions split across the boundary. |
| `hourlyWindowDays` | `7` | Days of hourly-grain rollups kept before lossless compaction to daily rows. |
| `dimensionCap` | `1000` | Per-(site, day, type) cardinality cap; the tail folds into `__other__`. |
| `rollupRetentionMonths` | `26` | Rollup retention (hard cap 26 months). |
| `spoolMaxBytes` | `52428800` | Back-pressure guard: beyond this spool size, oldest data is dropped and a CP warning raised - the site never falls over. |
| `honourGpc` | `true` | Visitors sending `Sec-GPC: 1` are never tracked beyond the anonymous tier. |
| `honourDnt` | `false` | Legacy `DNT: 1` support. |

## A note on multi-day unique visitors

Anonymous (Tier 1) visitor hashes are salted with a key that rotates daily and
is destroyed - the same person cannot be recognised across days, *by design*.
Multi-day "unique visitors" figures are therefore on a **daily-unique basis**
(a visitor returning on three days counts three times) and are labelled as
such in the CP. Consented (Tier 2, Pro) visitors are counted truly across
sessions and days.
