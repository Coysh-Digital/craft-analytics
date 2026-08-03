# Vendored front-end code

Everything in this directory is third-party code copied in verbatim. There is
no build step and no `package.json`: these files are served straight through
Craft's asset publisher, exactly as committed.

That makes provenance a manual discipline, so this file records it. Every entry
names the package, the pinned version, where the bytes came from, and the
SHA-256 of the file **as committed**. `tests/Unit/VendorProvenanceTest.php` and
the `cp-assets` CI job both re-check those hashes, so swapping a vendored file
without updating this table is a failing build rather than a silent change.

Checksums are taken of the working-tree file. `.gitattributes` normalises the
whole tree to LF (`* text=auto eol=lf`), and every file below is already
LF-only, so the working-tree bytes and the stored blob are identical on every
platform.

## Files

| File | Package | Version | Upstream path | SHA-256 | Added |
|---|---|---|---|---|---|
| `chart.umd.js` | [chart.js](https://github.com/chartjs/Chart.js) | 4.5.1 | `package/dist/chart.umd.js` | `ecc3cd1eeb8c34d2178e3f59fd63ec5a3d84358c11730af0b9958dc886d7652a` | 2026-08-03 |
| `LICENSE.chartjs.txt` | chart.js | 4.5.1 | `package/LICENSE.md` | — | 2026-08-03 |
| `jsvectormap.min.js` | [jsvectormap](https://github.com/themustafaomar/jsvectormap) | 1.7.0 | `package/dist/jsvectormap.min.js` | `ca3a014fc66d8249c4da0700f32bd6131cf0f0bb0801f8fafebfd7030f3cf50c` | 2026-07-21 |
| `jsvectormap.min.css` | jsvectormap | 1.7.0 | `package/dist/jsvectormap.min.css` | `66c86acfa5fe46cd917f9b0fb6249b6beaf02c12f5653c8b069a5b60eb2d47d3` | 2026-07-21 |
| `world-merc.js` | jsvectormap | 1.7.0 | `package/dist/maps/world-merc.js` | `d7f11a428ccfc0e0e110803b6fe28a06fa072541b6684b9397c6a24d5d2f4b62` | 2026-07-21 |
| `LICENSE.jsvectormap.txt` | jsvectormap | 1.7.0 | `package/LICENSE` | — | 2026-07-21 |

Tarball checksums, for anyone re-deriving the above from scratch:

| Tarball | SHA-256 |
|---|---|
| `chart.js-4.5.1.tgz` | `f540d98468457ac7a0aabb32006dfb066297e096c5ea063a5d80aa973d1c337a` |

## Re-deriving a file

```sh
curl -sSL -o pkg.tgz https://registry.npmjs.org/chart.js/-/chart.js-4.5.1.tgz
tar xzOf pkg.tgz package/dist/chart.umd.js | shasum -a 256
```

The same shape works for jsvectormap; substitute the package, version and
upstream path from the table.

## Notes

**The jsvectormap version was recovered, not recorded.** These three files were
committed in `2aa73fe` with no version anywhere — not in a filename, not in a
comment, not in a manifest. The version above was identified by hashing every
`.js` and `.css` file in each published jsvectormap release and finding the one
that matched all three committed files. All three match 1.7.0 exactly. It is a
recovered fact rather than a guess, but it is worth knowing it was recovered.

**`world-merc.js` carries no copyright notice of its own.** It is generated map
data rather than library source, and it ships inside the jsvectormap npm
package, which is MIT-licensed as a whole. That is the extent of what is
verifiable from the package itself; no separate upstream attribution for the
geometry is asserted here because none is stated there.

**Minified files carry no in-file banner except Chart.js.** `chart.umd.js` keeps
its `/*! Chart.js v4.5.1 … Released under the MIT License */` header, as
`THIRD-PARTY-LICENSES.md` requires. The jsvectormap builds are published without
one, so their attribution rests on `LICENSE.jsvectormap.txt` beside them and on
the entry in `THIRD-PARTY-LICENSES.md`.

**Chart.js is the full UMD bundle, deliberately.** A tree-shaken custom build
would save roughly 20 KB gzipped and cost the one property this directory trades
on: anyone can re-derive the table above from the published package and check it.
A bundle produced once on a developer's machine cannot be re-derived by anyone,
and with no build step in the repository there would be nothing to reproduce it
from. The 20 KB is the cheaper side of that trade.
