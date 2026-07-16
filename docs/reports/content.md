---
title: Content
description: Traffic by section, entry type and author - the report only a CMS can give you.
---

# Content

Google Analytics sees `/blog/why-privacy-first-analytics` and knows it's a
string. Craft Analytics knows it's an Entry, in the Blog section, of the
Article type, written by Sam.

That's the difference this screen exists to exploit, and it's the one thing a
generic analytics tool structurally cannot do, because it doesn't know your
content model and never will.

## Three questions

**Analytics → Content**, with three tabs.

### Sections

"How is the Blog doing, versus Case Studies, versus the marketing pages?"

The column worth looking at is **views per entry**. A section with 20 entries
and 2,000 views is not obviously beating one with 2 entries and 800 - the
second one is doing four times the work per piece. Raw totals reward whoever
publishes most.

Click a section to see the entries inside it.

### Entry types

"Do our Case Studies out-read our Articles?"

Useful when you're deciding what to make more of. If long-form guides get 5x
the views per entry of news posts, that's a content strategy telling you
something.

### Authors

"Who writes the things people actually read?"

A word of care: Craft 5 allows several authors per entry, and each of them
gets the **full** view count rather than a share. An article Sam co-wrote is
one of Sam's articles. So on a site with co-authored entries these views add
up to more than the site's total - that's correct, and stated on the screen so
nobody has to work out why the column doesn't sum.

Also: this is a report about content, not staff. It's a bad performance
review. A brilliant author writing about a niche subject will lose to a mediocre
one writing about pricing, every time, and that tells you about the subject.

## It costs nothing to store

There is no "content analytics" table. When a pageview matches an entry, the
plugin records the element ID it already had, and this screen joins that to
Craft's own tables when you look at it.

Which has a nice consequence: **it always reflects how your site is structured
right now**. Move an entry to another section and its history moves with it.
Rename a section and the report renames. Change an entry's author and the
credit follows.

That's the right default for a CMS. The question is "how is the Blog section
performing", not "how did the pages that happened to be in Blog last March
perform".

## What's not here

**Pages that aren't entries.** A template-only route, a plugin-provided page, a
search results page - they have views, but no element, so they can't be
attributed to a section. They're all on **Analytics → Pages**. If a lot of
your traffic is missing from Content, that's why.

**Drafts and revisions.** Excluded. A report about the Blog shouldn't include
the revision history of things in it.

**Deleted entries.** Excluded, because their views can't be attributed to
anything you can name any more.
