---
title: Locations & the geo database
description: Turning on country and region reporting, which needs a database file you install yourself.
---

# Locations & the geo database <Badge text="Pro" />

Country and region reporting needs two things: a database file, which you
install, and the setting turned on.

## Why there is a file to install

Working out that an address is in Manchester means looking it up in a table of
address ranges. You can either send the address to somebody's API, or hold the
table locally and look it up in memory.

Craft Analytics only does the second, because the first means sending your
visitors' IP addresses to a third party. There is no lookup service and no API
key. The trade-off is that you supply the table yourself: it is a file of
between 8 MB and 100 MB depending on which you choose, and its licence is not
ours to redistribute.

The plugin will not download it on its own. Changing a setting should not cause
your server to fetch 100 MB from a third party.

## Which database

**DB-IP Lite** is the recommended option: free, CC BY 4.0 and redistributable.
The country database is around 8 MB, the city one around 90 MB.

Download the free MMDB from [db-ip.com/db/lite.php](https://db-ip.com/db/lite.php).
You want the **MMDB** format, not CSV.

::: warning Attribution is required
The DB-IP Lite licence requires attribution wherever you use the data. The
plugin handles this: when it detects a DB-IP database, the Locations screen
and the generated privacy documents display the notice automatically. Don't
remove it.
:::

**MaxMind GeoLite2** also works and is often more accurate. It needs a free
MaxMind account, and its licence does not allow redistribution, so it is
supported as a bring-your-own-file option.
Download from [maxmind.com](https://www.maxmind.com/en/geolite2/signup), pick
`GeoLite2-City.mmdb` or `GeoLite2-Country.mmdb`.

City-level databases give you country and region. Country-level gives you
country only and leaves the Regions table empty, which is fine if countries are
all you need and saves you 80 MB.

## Installing it

Download the file, then point the command at it:

```bash
php craft craft-analytics/geo/install --from=/path/to/dbip-city-lite.mmdb
```

Or have the command fetch it, if you would rather not shuttle the file about:

```bash
php craft craft-analytics/geo/install --url=https://download.db-ip.com/free/dbip-city-lite-2026-07.mmdb.gz
```

The `--url` form makes that network call because you asked for it. It is the
only circumstance in which the plugin fetches anything.

The file lands in `storage/craft-analytics/geo.mmdb`. Check it:

```bash
php craft craft-analytics/geo/status
```

which tells you whether it is installed, where, how big, what type it is and
when it was built.

## Turning it on

**Settings → Plugins → Craft Analytics**, or in config:

```php
// config/craft-analytics.php
return [
    'enableGeo' => true,
];
```

Locations fill in from the next drain. Nothing is backfilled, because the
addresses that would have been resolved were discarded when those requests were
handled.

## Keeping it current

Address ranges change over time. DB-IP publishes monthly, and an old database
gradually returns more unknowns.

Put it on your cron:

```
0 3 1 * * php craft craft-analytics/geo/install --url=https://download.db-ip.com/free/dbip-city-lite-$(date +\%Y-\%m).mmdb.gz
```

The **Locations** screen shows the build date, so you can see when it was last
updated.

## What gets stored

When a request arrives, the address is resolved in memory to a country code and
a region name, and then discarded. It is not written to a table, a log or a
cache key. What reaches the database is:

| Stored | Not stored |
|---|---|
| `GB`, and `England` | The IP address, in any form |
| A count of sessions per country per day | Anything narrower than a region |
| | A city, a postcode, coordinates |

Even with a city-level database installed, **the plugin keeps only country and
region**. The city is read and discarded: a city is a small enough area to
start identifying people in, and the reports do not need one.

Storage grows with countries × regions × days, which is a few thousand rows a
year regardless of your traffic.

## Deployment

The database is a file in `storage/`, so it is not in your repo and does not
deploy with your code. Each environment needs its own copy: run the install
command as part of your provisioning, or on first deploy.

If it is missing, geo resolution returns nothing and everything else carries
on. The Locations screen tells you the database is not installed rather than
showing an empty table.

## Troubleshooting

**Locations is empty.** Check `geo/status` for the file, and check `enableGeo`
is on. Both are needed, and one without the other does nothing.

**Everything is "Unknown".** Your visitors are probably reaching PHP through a
proxy or load balancer, so the address Craft sees is the proxy's. Set
[`trustedHosts`](https://craftcms.com/docs/5.x/reference/config/general.html#trustedhosts)
in Craft's general config so it reads the real address from the forwarded
headers. This is a Craft setting rather than one of ours, and it affects
visitor counting as well as locations.

**Regions are empty but countries work.** You installed a country-level
database. Install the city-level one if you want regions.

**Local development shows nothing.** Your IP is `127.0.0.1`, which is not in
any country.
