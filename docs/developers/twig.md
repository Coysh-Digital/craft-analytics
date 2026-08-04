---
title: Twig API
description: Using your own analytics in your own templates.
---

# Twig API

Everything is under `craft.craftAnalytics`. It's all read-only and all
aggregate - there is no method that can return anything about an individual,
because there's nothing about an individual to return. You can't accidentally
leak what nobody's holding.

## Most read entries

The one everybody wants:

```twig
<h2>Most read this month</h2>
<ul>
    {% for entry in craft.craftAnalytics.popularEntries(5).all() %}
        <li>
            <a href="{{ entry.url }}">{{ entry.title }}</a>
            <span>{{ craft.craftAnalytics.views(entry)|number_format }} views</span>
        </li>
    {% endfor %}
</ul>
```

`popularEntries()` gives you an **entry query**, not an array, so carry on
refining it:

```twig
{# Most read blog posts of the last week #}
{% set popular = craft.craftAnalytics.popularEntries(5, '7d')
    .section('blog')
    .all() %}
```

It stays in view order through `.all()`. Adding `.section()` filters the list
without silently re-sorting it by post date, which is the thing you'd want and
the thing that usually doesn't happen.

**Signature:** `popularEntries(limit = 5, period = '30d', siteId = null)`

Periods are `today`, `yesterday`, `7d`, `30d`, `90d`, `12mo`, or an absolute
window as two dates - `'2026-01-01:2026-03-31'` - of up to 400 days. Anything
else is read as `30d`.

::: tip
It over-fetches internally, because some of the most-viewed elements will
since have been unpublished. Asking for 5 gives you 5 where 5 exist, rather
than 3 with no explanation.
:::

## Views for one entry

```twig
{{ craft.craftAnalytics.views(entry)|number_format }} views
{{ craft.craftAnalytics.views(entry, '7d') }} views this week
{{ craft.craftAnalytics.views(1234) }}  {# by ID #}
```

## Site totals

```twig
{% set stats = craft.craftAnalytics.totals() %}

{{ stats.views|number_format }} views
{{ stats.uniques|number_format }} visitors
{{ stats.sessions|number_format }} visits
{{ stats.bounceRate|number_format(1) }}% bounced
```

Available: `views`, `uniques`, `sessions`, `bounceRate`, `avgDurationMs`,
`avgViewsPerSession`, `avgDwellMs`.

**Signature:** `totals(siteId = null, period = '30d')`

::: warning
`uniques` is on a **daily-unique** basis - somebody who visits on three days
counts three times - and it's an estimate, accurate to about 1.6%. Both are
deliberate. See [How visitors are counted](../privacy/how-counting-works.md)
if you're about to put this number somewhere it matters.
:::

## Popular paths

When you want paths rather than elements:

```twig
{% for page in craft.craftAnalytics.popularPages(10, '7d') %}
    {{ page.path }} - {{ page.views }} views
{% endfor %}
```

Each row has `path`, `views`, `elementId`, `entrances`, `exits`, `bounces`,
`bounceRate`, `avgDwellMs`.

## Has this visitor opted out?

For suppressing your own consent banner:

```twig
{% if not craft.craftAnalytics.gpcDetected %}
    {% include '_partials/cookie-banner' %}
{% endif %}
```

`gpcDetected` is true when the browser sent `Sec-GPC: 1`. Those visitors are
never tracked beyond the anonymous tier no matter what any consent tool says,
so there's nothing to ask them.

## Custom events from JavaScript

```js
craftAnalytics.event('newsletter-signup');
craftAnalytics.event('quote-requested', { value: 250 });
```

Then make a [goal](../reports/goals.md) of type **Event** with target
`newsletter-signup`.

## Custom events from PHP

For things that happen on a POST, where there's no pageview to hang them off:

```php
use coyshdigital\craftanalytics\Plugin;

Plugin::getInstance()->getCapture()->trackEvent('quote-requested', 250.0);
```

Returns `false` if nothing was recorded, which is the normal answer on Lite,
with events off, or for a visitor sending GPC. It respects every privacy rule
the rest of the plugin does - your code can't opt someone in.

## Caching your own analytics-driven markup

If you're rendering "most read" on every page load, cache it. The queries are
cheap but they're not free, and this changes about as often as you'd expect:

```twig
{% cache for 1 hour %}
    {% for entry in craft.craftAnalytics.popularEntries(5).all() %}
        …
    {% endfor %}
{% endcache %}
```
