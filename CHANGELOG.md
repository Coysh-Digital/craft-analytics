# Release Notes for Craft Analytics

## Unreleased

### Added
- Initial plugin scaffold: editions (Lite/Pro), settings model, config file support, dimensions table, cross-database upsert helper, rotating salt store.
- Server-side pageview capture on `Response::EVENT_AFTER_SEND`, after `fastcgi_finish_request()` — no added time-to-first-byte.
- Rotating visitor-hash salt with automatic rotation on a configurable window, aligned to a quiet hour, destroying the previous salt.
- Bot filtering via CrawlerDetect plus headless/automation and missing-`Accept-Language` heuristics.
- Global Privacy Control (and optional `DNT`) support.
- Write path with `spool` (default), `queue` and `direct` drivers, plus a spool back-pressure guard.
- Ephemeral session hot layer (cache-backed) powering session metrics without raw hit rows.
- `craft-analytics/drain/run` console command (with `--watch`), crash-safe and idempotent.
- `craft-analytics/salt/rotate` console command.
- Benchmark harness proving the TTFB and capture-cost claims.
