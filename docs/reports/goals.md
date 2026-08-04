---
title: Goals
description: Counting the things worth counting, and what each one was worth.
---

# Goals <Badge text="Pro" />

Pageviews tell you people arrived. Goals tell you whether they did anything.

A goal names something worth counting - reaching the thank-you page, firing a
signup event, reading a case study to the bottom - and optionally what one is
worth. Then every report can tell you not just "500 people read the blog" but
"500 people read the blog and 12 of them enquired, which was £600".

## Making one

**Settings → Plugins → Craft Analytics → Goals & funnels → New goal**.

| Field | What it's for |
|---|---|
| **Name** | What it's called in reports. "Enquiry form", not "goal 3". |
| **Handle** | Used by funnel steps and the Twig API. Must be unique. |
| **Type** | See below. |
| **Target** | What has to happen. Means something different per type. |
| **Value** | What one conversion is worth. Leave at 0 if it isn't about money. |
| **Site** | All sites, or just one. |

### The five types

**Page visit** - they reached a path. `*` is a wildcard.

```
/thank-you              exactly that page
/checkout/*             anything under /checkout/
/guides/*/download      wildcards in the middle work too
```

Query strings are ignored, so `/thank-you` matches `/thank-you?ref=email`.
That is almost always what you want: a goal that ignored query strings would
miss all your campaign traffic.

**Event** - a named event fired, from your own JavaScript:

```js
craftAnalytics.event('signup');
craftAnalytics.event('quote-requested', { value: 250 });
```

Set the target to `signup`. A page called `/signup` will *not* match - the
kind matters, not just the name.

**Entry visit** - they viewed a specific entry. Target is an entry ID. Useful
for "did anyone read the thing we spent three weeks on".

**Session duration** - they stayed at least N seconds. Target is a number of
seconds. Needs hybrid or client mode; the server can't know.

**Scroll depth** - they read at least N% down a page. Target is `25`, `50`,
`75` or `100`. Also needs the beacon.

## Goals convert once per session

Someone who reloads the thank-you page three times has converted **once**.
Counting three would overstate it, and you would be making decisions on that
number.

This is why conversion rate is conversions ÷ **sessions**, not ÷ pageviews.
Dividing by pageviews would understate every rate on your site by however much
people browse.

## Two things that surprise people

**Goals are not retrospective.** A goal you add today starts counting today.
It cannot be applied to last month, because last month's individual pageviews
no longer exist: they were folded into daily totals and the visits themselves
discarded. This is the same design that removes the need for a cookie banner.

If you are about to run a campaign, create the goal first.

**Deleting a goal deletes its conversions**, since they are only meaningful as
that goal's conversions. The control panel warns you before it happens.

## Goals live in the database

Which means you can create one wherever you can reach the control panel,
production included. There is nothing to deploy and nothing to apply.

Until version 2.0.0 they were kept in project config, so they arrived in
production on a deploy. That sounded right and worked badly: project config is
read-only wherever `allowAdminChanges` is off, which is every production site,
so the goals screen there could only refuse. The person who wanted a goal
could not add one, and the person who could was a deploy away.

The trade-off, stated plainly: **goals no longer travel between environments.**
One created in staging will not appear in production, and each environment
keeps its own. If you are upgrading, see the 2.0.0 entry in the changelog for
what happens to the definitions already in your project config.

## Reading the report

**Analytics → Goals** lists every goal, including any sitting at zero. A goal
at zero usually means its target is wrong, so it is shown rather than hidden.

Where a goal has a value, campaigns get credit for it under your attribution
model, so **Analytics → Campaigns** can tell you that the newsletter drove
12.5 conversions worth £430 - the .5 because a session touched by two
campaigns splits its credit rather than being counted twice.

## What goals cost to store

Nothing, which is worth explaining. Page, event and entry goals are matched
the moment a pageview arrives, and the session records only which goal handles
matched - never the pages themselves. Session state is therefore bounded by the
number of goals you have defined rather than by how much anyone browses.

Duration and scroll goals are worked out when the session ends, from two
numbers already held on it.

Neither creates a per-visitor row or a stored journey.
