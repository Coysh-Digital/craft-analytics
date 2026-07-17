---
title: Funnels
description: Where people drop out of a sequence of goals, and how many.
---

# Funnels <Badge text="Pro" />

A funnel is a sequence of goals. It answers one question: where do people drop
out?

```
1. Landed on blog          412  ████████████████████  100%
2. Read a post             289  ██████████████         70%
   ↳ 123 left here (29.9%)
3. Reached services         31  █▌                      7.5%
   ↳ 258 left here (89.3%)
```

The third number is the useful one here. The blog is not failing; people read
a post and then do not find their way to what you sell.

## Making one

You need at least two [goals](goals.md) first, because a funnel step is a
goal. That way a step and a goal cannot disagree about what a conversion is.

**Settings → Plugins → Craft Analytics → Goals & funnels → New funnel**, then
add the goals in the order they should happen.

## Order is enforced

A session counts at step 3 only if it converted step 1, then step 2, then step
3. Somebody who arrived on `/services` first and then read a blog post has not
walked this funnel, and counting them would make a broken flow look healthy.

Here is an example with four visitors:

| Visitor | What they did | Steps reached |
|---|---|---|
| A | blog → post → services | **3** |
| B | blog → post | **2** |
| C | **services** → blog → post | **2** - the services visit came first, so it isn't step 3 |
| D | blog | **1** |

Result: 4 / 3 / 1. Note that C did convert the "reached services" goal - the
Goals report counts them. They just didn't walk the funnel in order, so the
funnel doesn't credit them at step 3. Both numbers are right; they're
answering different questions.

Unrelated goals in between don't break anything. blog → newsletter signup →
post → live chat → services is still a complete walk.

### Duration and scroll steps are conditions, not positions

A **session duration** or **scroll depth** step behaves differently, and
deliberately: those are properties of the whole visit rather than things that
happened at a particular moment. "Stayed 60 seconds" isn't true *at* a point
you could slot into a sequence - it's true of the visit or it isn't.

So a step like that **gates** the funnel without taking a place in the order:

```
1. Read a guide
2. Stayed 60 seconds      ← a condition on the session, not a position
3. Requested a quote      ← must still come after step 1
```

Somebody who read a guide, requested a quote and was there for two minutes
completes all three. Somebody who did the same in forty seconds stops at step
1, because step 2 gates them.

## Within a session, and only within a session

**There is no cross-session funnel.**

Visitors are identified by a hash built from a salt that is replaced every 24
hours. Once it has rotated there is no way to know that today's visitor is
yesterday's, which is what removes the need for a cookie banner.

A purchase decided over three visits is therefore three sessions, and each is
reported separately rather than stitched together.

Multi-visit funnels would require identifying returning visitors, which
requires a cookie and therefore consent. Craft Analytics supports that - see
[Privacy & compliance](../privacy/README.md) - and it is off by default, since
turning it on changes your legal position.

## Reading the numbers

- **Sessions** at each step is "reached at least this far", so it only ever
  goes down.
- **Drop-off** is the difference between neighbouring steps, worked out when
  you look rather than stored.
- **Of first step** is the share of everyone who started.
- **Completion rate** is the last step ÷ the first.

A step showing 0 usually means its goal is not matching. Check it on the Goals
screen before concluding that your checkout is broken.

## Funnels live in project config

Like goals. Steps are stored by goal handle, not ID, so a funnel survives
being deployed to an environment where the auto-increment IDs are different.

```yaml
# config/project/craftAnalytics/funnels/blogToEnquiry--….yaml
enabled: true
handle: blogToEnquiry
name: 'Blog to enquiry'
siteId: null
sortOrder: 0
steps:
  - landed
  - readPost
  - enquired
```
