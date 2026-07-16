# Third-Party Licenses

Runtime Composer dependencies and bundled third-party material. Every entry
here must be a permissive licence (MIT, Apache-2.0, BSD, ISC, CC BY) per the
project's IP rules — no GPL/AGPL/LGPL/EUPL/SSPL code may enter this codebase.

| Package / material | Licence | Used for |
|---|---|---|
| [donatj/phpuseragentparser](https://github.com/donatj/PhpUserAgent) | MIT | User-agent parsing (browser, OS, device type) for the devices rollup |
| [jaybizzle/crawler-detect](https://github.com/JayBizzle/Crawler-Detect) | MIT | Bot/crawler user-agent filtering |
| [maxmind-db/reader](https://github.com/maxmind/MaxMind-DB-Reader-php) | Apache-2.0 | Reading the local geolocation database (Pro) |

Data, not code — installed by the operator, never shipped by us:

| Material | Licence | Used for |
|---|---|---|
| DB-IP Lite country/city database | CC BY 4.0 — **attribution required**, shown in the CP and the generated privacy documents whenever the database is in use | Local geo resolution; the address is resolved in memory and discarded |
| MaxMind GeoLite2 (optional alternative) | MaxMind's own EULA — requires an account and is not redistributable, so we read it but never ship it | Same |

Dev-only (never shipped to a production site):

| Package | Licence | Used for |
|---|---|---|
| yiisoft/yii2-redis | BSD-3-Clause | Exercising the Redis unique-counter driver in tests |
| pestphp/pest, craftcms/phpstan, craftcms/ecs | MIT | Test and static-analysis tooling |

## Copyleft in the dependency tree (not ours)

`composer licenses --no-dev` surfaces two copyleft packages:
`enshrined/svg-sanitize` (GPL-2.0-or-later) and `ezyang/htmlpurifier`
(LGPL-2.1-or-later). **Both are transitive dependencies of `craftcms/cms`
itself**, present in every Craft installation whether or not this plugin is
installed. This plugin neither requires them, links against them, nor copies
from them, and adds no copyleft dependency of its own.

Full licence texts ship in `vendor/` via Composer. Any verbatim-copied code
(as opposed to a Composer dependency) must additionally preserve its original
copyright header in-file and be listed here.
