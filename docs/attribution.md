# Conceptual attribution

This project implements several well-known techniques from first principles.
No code was copied from copyleft or unlicensed sources; this file records the
prior art each *idea* derives from and why our implementation is original.

## Rotating-salt visitor hashing

- **Prior art:** Publicly documented by Plausible Analytics ("data policy" docs)
  and Fathom Analytics; the underlying idea (keyed, time-boxed pseudonymisation)
  is standard privacy-engineering practice discussed in GDPR/WP29 anonymisation
  guidance.
- **Our implementation:** `SHA-256(dailySalt ‖ ip ‖ userAgent ‖ siteId)` truncated
  to 8 bytes, salt rotated on a configurable window (default 24 h) with the prior
  salt destroyed. Written from the description of the technique only; no source
  code from Plausible (AGPL-3.0) or any other implementation was read or used.
- **Deliberate consequence:** cross-day re-identification is impossible, so
  multi-day "unique visitor" figures are on a *daily-unique basis* and are
  labelled as such in the UI.

## HyperLogLog cardinality estimation

- **Prior art:** Flajolet, Fusy, Gandouet, Meunier, *"HyperLogLog: the analysis
  of a near-optimal cardinality estimation algorithm"* (2007), and the
  sparse-representation refinement in Heule, Nunkesser, Hall, *"HyperLogLog in
  Practice"* (Google, 2013). Both are published papers; algorithms as such are
  not copyrightable.
- **Our implementation:** original pure-PHP implementation in
  `src/uniques/Hll.php` (phase 3), written from the papers, with a custom
  binary serialisation format. Redis's `PFADD`/`PFMERGE` commands are used via
  their public API where Redis is available; Redis source code (BSD-3-Clause
  anyway) was not consulted.

## Server-side capture after response flush

- **Prior art:** `fastcgi_finish_request()` is documented PHP behaviour; the
  pattern of doing deferred work after flushing is folk knowledge (PHP manual,
  Symfony `kernel.terminate`).
- **Our implementation:** built on Yii's `Response::EVENT_AFTER_SEND` as exposed
  by Craft CMS.

## What was *not* used

Per the project IP rules: `samuelreichor/craft-insights` was never read,
fetched, or consulted in any form. Plausible (AGPL-3.0), Matomo (GPL-3.0),
Shynet (AGPL), and GoatCounter (EUPL-1.2) source code was not read; only their
public, non-code documentation informed technique selection.
