---
title: Content
description: Traffic by section, entry type and author.
---

# Content

To most analytics tools, `/blog/why-privacy-first-analytics` is a string.
Craft Analytics knows it is an Entry in the Blog section, of the Article type,
written by Sam.

This screen is built on that. A general-purpose analytics tool cannot produce
these reports, because it does not know your content model.

## Three questions

**Analytics → Content**, with three tabs.

### Sections

"How is the Blog doing, versus Case Studies, versus the marketing pages?"

The column worth looking at is **views per entry**. A section with 20 entries
and 2,000 views is not clearly beating one with 2 entries and 800: the second
is doing four times the work per piece. Raw totals just reward whoever
publishes most.

Click a section to see the entries inside it.

### Entry types

"Do our Case Studies out-read our Articles?"

Useful when deciding what to make more of. If long-form guides get five times
the views per entry that news posts do, that is worth knowing.

### Authors

"Who writes the things people actually read?"

One caveat: Craft 5 allows several authors per entry, and each gets the full
view count rather than a share, on the basis that an article Sam co-wrote is
one of Sam's articles. On a site with co-authored entries these views therefore
add up to more than the site's total. The screen says so.

This is a report about content rather than about staff. An author writing about
a niche subject will lose to one writing about pricing, which tells you about
the subject more than the author.

## It costs nothing to store

There is no separate content-analytics table. When a pageview matches an
entry, the plugin records the element ID it already had, and this screen joins
that to Craft's own tables when you look at it.

That means the report **always reflects how your site is structured now**. Move
an entry to another section and its history moves with it; rename a section and
the report renames; change an author and the credit follows.

For a CMS that is the more useful default: the question is usually how the Blog
section is performing, not how the pages that were in Blog last March
performed.

## What's not here

**Pages that are not entries.** A template-only route, a plugin-provided page
or a search results page has views but no element, so it cannot be attributed
to a section. Those are all on **Analytics → Pages**. If a lot of your traffic
is missing from Content, this is usually why.

**Drafts and revisions.** Excluded, so a report about the Blog does not include
the revision history of things in it.

**Deleted entries.** Excluded, since their views can no longer be attributed to
anything you can name.
