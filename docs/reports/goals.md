---
title: Goals
description: Counting the things that actually matter, and what they were worth.
---

# Goals <Badge text="Pro" />

Pageviews tell you that people came. Goals tell you whether anything happened.

A goal names something worth counting - reaching the thank-you page, firing a
signup event, reading a case study to the bottom - and optionally what one is
worth. Then every report can tell you not just "500 people read the blog" but
"500 people read the blog and 12 of them enquired, which was £600".

## Making one

**Settings → Plugins → Craft Analytics → Goals & funnels → New goal**.

| Field | What it's for |
|---|---|
| **Name** | What it's called in reports. "Enquiry form", not "goal 3". |
| **Handle** | Used in project config and Twig. |
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
That's almost always what you want, and a goal that missed your campaign
traffic would be wrong in the most annoying possible way.

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

## Once per session. Always.

Someone who reloads the thank-you page three times converted **once**.
Counting three would be flattering, and wrong, and you'd make decisions on it.

This is why conversion rate is conversions ÷ **sessions**, not ÷ pageviews.
Dividing by pageviews would understate every rate on your site by however much
people browse.

## Two things that surprise people

**Goals aren't retrospective.** A goal you add today starts counting today. It
cannot be applied to last month, because last month's individual pageviews no
longer exist - they were folded into daily totals and the visits themselves
were thrown away. That's the same design that means you don't need a cookie
banner. It cuts both ways, and this is the way it cuts against you.

So: if you're about to run a campaign, make the goal *first*.

**Deleting a goal deletes its conversions.** They're only meaningful as that
goal's conversions - a count nobody can name is worse than no count - so
they're removed with it. The CP says so before it happens.

## Goals live in project config

Which means they deploy like everything else: make them in your dev
environment, commit `config/project/craftAnalytics/goals/`, and they arrive in
production on your next `project-config/apply`. Nobody has to retype them into
the production CP at 6pm on a Friday.

```yaml
# config/project/craftAnalytics/goals/enquiry--a1b2c3d4-….yaml
enabled: true
handle: enquiry
name: 'Enquiry form'
siteId: null
sortOrder: 1
target: /thank-you
type: url
value: 50.0
```

If `allowAdminChanges` is off in production - as it should be - the goals
screen there is read-only and says so, rather than letting you save something
that would be silently discarded.

## Reading the report

**Analytics → Goals** shows every goal, including the ones sitting at zero. A
goal at zero is a real answer, and usually means the target is wrong. Hiding
it is how that goes unnoticed for a month.

Where a goal has a value, campaigns get credit for it under your attribution
model, so **Analytics → Campaigns** can tell you that the newsletter drove
12.5 conversions worth £430 - the .5 because a session touched by two
campaigns splits its credit rather than being counted twice.

## Where goals cost nothing

Worth knowing, since it explains the design: page, event and entry goals are
matched *the moment the pageview arrives*, and the session only remembers
which goal handles matched. It never stores the pages you visited. So session
state is bounded by how many goals you've defined - a handful - rather than by
how much any visitor browses.

Duration and scroll goals are judged when the session ends, from two numbers
already on it.

Either way: no per-visitor rows, no stored journeys, nothing to erase later.
