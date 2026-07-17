---
title: Funnels
description: Where people leave, and how many of them.
---

# Funnels <Badge text="Pro" />

A funnel is goals in order. It answers one question: **where do people drop
out?**

```
1. Landed on blog          412  ████████████████████  100%
2. Read a post             289  ██████████████         70%
   ↳ 123 left here (29.9%)
3. Reached services         31  █▌                      7.5%
   ↳ 258 left here (89.3%)
```

That third number is the useful one. It's not that your blog is failing; it's
that people read a post and then never find their way to what you sell.

## Making one

You need at least two [goals](goals.md) first - a funnel step *is* a goal.
That's deliberate: it means a step and a goal can never disagree about what a
conversion is.

**Settings → Plugins → Craft Analytics → Goals & funnels → New funnel**, then
add the goals in the order they should happen.

## Order is enforced

This is the bit worth understanding, because it's where funnels in other tools
quietly lie to you.

A session counts at step 3 only if it converted step 1, *then* step 2, *then*
step 3. Somebody who wandered onto `/services` first, then read a blog post,
did not walk this funnel - and counting them would turn a broken flow into a
healthy-looking one, which is the exact opposite of what a funnel is for.

Here's a real example from testing, four visitors:

| Visitor | What they did | Steps reached |
|---|---|---|
| A | blog → post → services | **3** |
| B | blog → post | **2** |
| C | **services** → blog → post | **2** - the services visit came first, so it isn't step 3 |
| D | blog | **1** |

Result: 4 / 3 / 1. Note that C *did* convert the "reached services" goal - the
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

**There is no cross-session funnel, and there cannot be one.**

Craft Analytics identifies visitors with a hash that includes a salt, and that
salt is destroyed and replaced every 24 hours. After it rotates, there is no
way - not a slow way, not an expensive way, *no way* - to know that today's
visitor is yesterday's. That's the property that means you don't need a cookie
banner.

So a purchase decided over three visits is three sessions, and this reports
each of them honestly rather than stitching them together and pretending.

If you genuinely need multi-visit funnels, that requires identifying returning
visitors, which requires a cookie, which requires consent. Craft Analytics
supports that (see [Privacy & compliance](../privacy/README.md)) and it is off by default,
because turning it on changes your legal position and that should be a
decision, not an accident.

## Reading the numbers

- **Sessions** at each step is "reached at least this far", so it only ever
  goes down.
- **Drop-off** is the difference between neighbouring steps - computed when you
  look, not stored, because storing a subtraction is how two numbers get to
  disagree.
- **Of first step** is the share of everyone who started.
- **Completion rate** is the last step ÷ the first.

A step showing 0 usually means its goal isn't matching. Check it on the Goals
screen before you conclude your checkout is broken.

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
