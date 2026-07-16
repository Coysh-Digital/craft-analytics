---
title: GraphQL API
description: Reading your traffic from GraphQL.
---

# GraphQL API <Badge text="Pro" />

Two queries, behind a schema component of their own.

## Turn it on first

The queries **don't exist** until a schema grants them. Not "exist and return
an error" - the field genuinely isn't on the schema, so an unauthorised token
gets `Cannot query field "craftAnalyticsTotals" on type "Query"`.

The numbers expose nobody, but they're your business rather than the
internet's, so the default is no.

**Settings → GraphQL → Schemas**, pick a schema, and tick **Analytics → Read
traffic reports**. In project config that's:

```yaml
scope:
  - 'craftAnalytics.read:read'
  - 'sites.054f4ae9-…:read'
```

You need the site scope too, or Craft refuses the query before it reaches us.

## craftAnalyticsTotals

```graphql
{
  craftAnalyticsTotals(period: "30d") {
    views
    uniques
    sessions
    bounceRate
    avgDurationMs
    avgViewsPerSession
  }
}
```

```json
{
  "data": {
    "craftAnalyticsTotals": {
      "views": 4812,
      "uniques": 1203,
      "sessions": 1580,
      "bounceRate": 45.61
    }
  }
}
```

**Arguments:** `siteId` (defaults to the primary site), `period` (`today`,
`yesterday`, `7d`, `30d`, `90d`, `12mo` - defaults to `30d`).

## craftAnalyticsTopPages

```graphql
{
  craftAnalyticsTopPages(period: "7d", limit: 10) {
    path
    views
    elementId
    entrances
    exits
    bounceRate
    avgDwellMs
  }
}
```

`limit` is capped at 200. `elementId` is null for pages that aren't entries -
a template-only route, say - which is exactly the hook you need to join this
to an `entries` query in the same request.

## Read the field descriptions

They carry the caveats, and the caveats matter:

- **`uniques`** is on a daily-unique basis and estimated to about ±1.6%. If
  you're piping this into a dashboard that someone will make decisions from,
  read [how visitors are counted](../privacy/how-counting-works.md) first.
- **`avgDwellMs`** is always 0 in server-only tracking mode, because nothing on
  the server knows when someone left.
- **`path`** can be `__other__`, which is where values past the daily
  cardinality cap are folded. No views are lost to it, but it isn't a page you
  can visit.

They're in the schema itself, so anything that introspects - Insomnia,
Altair, your codegen - shows them without anyone reading this page.
