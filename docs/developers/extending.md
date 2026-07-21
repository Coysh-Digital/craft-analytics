---
title: Extending
description: Hooking your own site's module into Craft Analytics - segments, your own visitor IDs, and the events you can already listen to.
---

# Extending

Craft Analytics deliberately knows very little about your visitors. It counts
them with a hash it throws away every 24 hours, and it holds no record of any
individual. That is the whole product.

Your site, though, knows plenty. It knows who is signed in, what plan they are
on, whether they are a member or a guest. This page is how you hand some of
that over - on purpose, in a shape that keeps the privacy guarantees intact.

Nothing here is on by default. A site that installs the plugin and writes no
module code gets exactly what it got before.

::: tip
Everything on this page is **Pro**. On Lite the registry stays empty, the
events never fire, and no extra row is ever written - gated in the services
themselves, so no route or handler can reach around it.
:::

## What you cannot do

Worth saying first, because it shapes everything else.

- **A browser privacy signal is final.** If a visitor sends GPC (or DNT, where
  you have turned that on), nothing on this page runs for them. Not a segment,
  not an ID. A signal you can overrule is not a signal.
- **You cannot create a consented visitor.** The identity hook fires only for
  someone who has already affirmatively agreed, on a site that has consent
  switched on. It decides what their ID *is*, never whether they have one.
- **You cannot store something about one person in the aggregate layer.**
  Segments are counters. There is no key you can invent that puts a name in a
  rollup - the value is a dimension shared by everybody who matches it.

## Segments

A segment is a key and a value describing a visit: `plan=pro`, `role=member`,
`signed_in=yes`. Each one becomes a row in a **Segments** report showing
sessions, views, bounce rate and average duration for that group.

They cost one row per value per day, whether that value covered ten visits or
ten million.

### 1. Declare them

In your module's `init()`:

```php
use coyshdigital\craftanalytics\events\RegisterSegmentsEvent;
use coyshdigital\craftanalytics\models\SegmentDefinition;
use coyshdigital\craftanalytics\services\SegmentRegistry;
use yii\base\Event;

Event::on(
    SegmentRegistry::class,
    SegmentRegistry::EVENT_REGISTER_SEGMENTS,
    function(RegisterSegmentsEvent $event) {
        $event->segments[] = new SegmentDefinition(
            key: 'plan',
            label: 'Plan tier',
            values: ['free', 'pro', 'enterprise'],
        );
    },
);
```

Declaring is not paperwork. It is the allowlist: anything not declared here is
dropped, from every direction, silently. It is also where the report gets its
heading, and where the **Segments** nav item comes from - no declarations, no
screen.

| Argument | What it does |
|---|---|
| `key` | How it is identified in code and stored. Lower-case letters, digits, `_` and `-`, up to 32 characters. No colons. |
| `label` | The report heading. Defaults to a humanised key, so `account_type` reads as "Account type". |
| `values` | The values this segment may take. Optional, and worth the two minutes - see below. |
| `fromBeacon` | Whether the browser may set it. **Off by default.** |

### 2. Put values on them

```php
use coyshdigital\craftanalytics\events\DefineSegmentsEvent;

Event::on(
    SegmentRegistry::class,
    SegmentRegistry::EVENT_DEFINE_SEGMENTS,
    function(DefineSegmentsEvent $event) {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user) {
            $event->segments['plan'] = $user->planHandle;
        }
    },
);
```

This runs on the request thread of a page that has **already been sent**, so
it cannot slow anything down for the visitor - but the PHP worker is held
until it returns, so keep it to things you can answer cheaply.

### 3. Or from the browser, for cached pages

A page served from Blitz, Varnish or Cloudflare never reached PHP, so the
handler above never ran for it. Declare the segment `fromBeacon: true` and set
it client-side instead:

```html
<script>window.craftAnalyticsSegments = { plan: 'pro' };</script>
```

or, once the tracker has loaded:

```js
craftAnalytics.segment('plan', 'pro');
```

Both are read when the beacon is sent, so either is in time. The inline object
is the one to use if the value is known while the page is being built.

::: warning
`fromBeacon` is off by default for a reason. The beacon endpoint is anonymous
and unauthenticated - it has to be, because it is posted to from pages PHP
never saw - so anyone can forge a request to it. A segment that decides
something you care about should be resolved on the server, where only your own
code can set it. When both set the same key, **the server wins.**
:::

### The rules values follow

- **Declare `values` and casing is fixed for you.** A template writing `Pro`
  and one writing `pro` become one row, in the casing you declared. Anything
  not on the list is dropped.
- **Leave `values` out and values are lower-cased instead.** Same protection
  against one thing becoming two rows, less precision.
- Values are trimmed, stripped of control characters, and truncated at 100
  characters.
- **Five segments per visit, maximum.** Every segment is another row per
  session; five is more than a report needs and low enough that a loop with a
  bug in it cannot become a storage problem. Past five, declaration order wins.
- **A segment is read once per visit.** Whoever the visitor was when they
  arrived is what the session counts as - somebody who signs in halfway
  through is still counted as a guest for that visit. That is the honest
  answer to "where did this session come from".
- The daily cardinality cap applies, exactly as it does to paths. Beyond
  `dimensionCap` distinct values in a day, the rest fold into `__other__` and
  the report says so. If you see that number moving, a segment is being given
  far more values than it was meant to have.

### What it looks like

Sessions appear on the Segments screen once they have closed and the drain has
run - not instantly. A visit counts in **every** segment it belongs to, so the
tables deliberately do not add up to your total sessions.

## Identifying your own users

This is the other half, and it is a different kind of thing. Segments are
counters; this puts a name in a table.

By default a consented visitor gets 16 random bytes as an ID - meaningful
inside this plugin and nowhere else. If your site has its own identifier for
that person, you can use it instead:

```php
use coyshdigital\craftanalytics\events\DefineVisitorIdEvent;
use coyshdigital\craftanalytics\services\ConsentService;

Event::on(
    ConsentService::class,
    ConsentService::EVENT_DEFINE_VISITOR_ID,
    function(DefineVisitorIdEvent $event) {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user && $user->customerNumber) {
            $event->visitorId = 'cust_' . $user->customerNumber;
        }
    },
);
```

The ID must be 1-64 characters of letters, digits, underscore, dot, colon or
hyphen. Anything else is refused, with a warning in the log, and the issued ID
is used instead - storing a mangled version would put rows in the journeys
table that no subject access request could ever find again.

### What has to be true first

All of it, or this does nothing:

| | |
|---|---|
| Pro edition | Tier-2 is a paid feature |
| `enableConsent` | otherwise the plugin is cookieless and says no to everything |
| `enableJourneys` | otherwise there is no per-visitor table to write to |
| The visitor consented | absence is never consent |
| No GPC or DNT | and that one you cannot override |

### What changes when you do this

Be deliberate about this bit.

- The **journeys** table and the **consent log** are now keyed by your
  identifier. `PrivacyService::export()` and `erase()` take it directly, so a
  subject request is answered with the customer number the rest of your
  business already uses.
- That data stops being pseudonymous and becomes **directly identifiable**. It
  can be joined to your own records - which is the point of supplying it, and
  also the reason it is a bigger commitment than it looks.
- The **Privacy screen says so**, as soon as a handler is attached. That
  warning is not decoration; it is what your DPO needs to read.
- The consent **cookie is not changed**. Your identifier lives in your
  database, not on the visitor's device.

## The events that were already there

Three more, documented here for the first time.

### `TrackEvent` - change or drop a hit

```php
use coyshdigital\craftanalytics\events\TrackEvent;
use coyshdigital\craftanalytics\ingest\CaptureService;

Event::on(
    CaptureService::class,
    CaptureService::EVENT_BEFORE_TRACK,
    function(TrackEvent $event) {
        if (str_starts_with($event->hit->path, '/internal/')) {
            $event->isValid = false;   // never recorded
        }
    },
);
```

Fires just before a hit reaches the write path, on both the server-side and
`trackEvent()` routes. `$event->hit` is a readonly `Hit`; replace it wholesale
if you need to change something.

### `DefineConsentEvent` - resolve consent from your CMP

```php
use coyshdigital\craftanalytics\enums\ConsentState;
use coyshdigital\craftanalytics\events\DefineConsentEvent;

Event::on(
    ConsentService::class,
    ConsentService::EVENT_DEFINE_CONSENT,
    function(DefineConsentEvent $event) {
        if (myCmp()->hasAnalyticsConsent()) {
            $event->state = ConsentState::Granted;
        }
    },
);
```

Fires last, with whatever the plugin worked out from the cookies it can see
already in `$event->state`. Your site knows things it does not. The one thing
this cannot do is reverse a GPC signal, which is checked before the event and
is not ours or yours to overrule.

### `RegisterChannelRulesEvent` - your own referrer rules

```php
use coyshdigital\craftanalytics\enums\Channel;
use coyshdigital\craftanalytics\events\RegisterChannelRulesEvent;
use coyshdigital\craftanalytics\services\ChannelClassifier;

Event::on(
    ChannelClassifier::class,
    ChannelClassifier::EVENT_REGISTER_CHANNEL_RULES,
    function(RegisterChannelRulesEvent $event) {
        $event->rules['ourforum.example'] = Channel::Social;
    },
);
```

A fragment matches the host itself or any subdomain of it; a trailing dot
(`'google.'`) matches any TLD.

### Recording an event from your own code

Not an extension point so much as the front door, but it belongs on this page:

```php
use coyshdigital\craftanalytics\Plugin;

Plugin::getInstance()->getCapture()->trackEvent('quote:requested', 250.00);
```

This works on POSTs and redirects, which are never pageviews - which is the
point, because that is where the interesting things happen. Returns `false`
when nothing was recorded: on Lite, with events off, or for a visitor sending
GPC.

## A worked example

The whole thing, for a membership site that wants to see how signed-in members
behave and to answer subject requests with its own member numbers.

`modules/sitemodule/SiteModule.php`:

```php
use coyshdigital\craftanalytics\events\DefineSegmentsEvent;
use coyshdigital\craftanalytics\events\DefineVisitorIdEvent;
use coyshdigital\craftanalytics\events\RegisterSegmentsEvent;
use coyshdigital\craftanalytics\models\SegmentDefinition;
use coyshdigital\craftanalytics\services\ConsentService;
use coyshdigital\craftanalytics\services\SegmentRegistry;
use Craft;
use yii\base\Event;
use yii\base\Module;

class SiteModule extends Module
{
    public function init(): void
    {
        parent::init();

        // Aggregate: no consent needed, nothing per-person stored.
        Event::on(
            SegmentRegistry::class,
            SegmentRegistry::EVENT_REGISTER_SEGMENTS,
            function(RegisterSegmentsEvent $event) {
                $event->segments[] = new SegmentDefinition(
                    key: 'membership',
                    label: 'Membership',
                    values: ['guest', 'member', 'lifetime'],
                );
            },
        );

        Event::on(
            SegmentRegistry::class,
            SegmentRegistry::EVENT_DEFINE_SEGMENTS,
            function(DefineSegmentsEvent $event) {
                $user = Craft::$app->getUser()->getIdentity();

                $event->segments['membership'] = match (true) {
                    $user === null => 'guest',
                    (bool)$user->isLifetime => 'lifetime',
                    default => 'member',
                };
            },
        );

        // Identified: only for members who consented, and only because this
        // site turned consent and journeys on deliberately.
        Event::on(
            ConsentService::class,
            ConsentService::EVENT_DEFINE_VISITOR_ID,
            function(DefineVisitorIdEvent $event) {
                $user = Craft::$app->getUser()->getIdentity();

                if ($user !== null && $user->memberNumber) {
                    $event->visitorId = 'member_' . $user->memberNumber;
                }
            },
        );
    }
}
```

With that in place: the Segments screen breaks traffic down by membership for
everybody, consent or not - and for the members who agreed, the Privacy screen
can export or erase a journey by member number.

## What each choice puts in your database

Worth reading before you ship either one.

| | Segments | Your own visitor IDs |
|---|---|---|
| Needs consent | No | Yes |
| Cookie | None | The existing consent cookie, unchanged |
| What is stored | Counters, keyed by a shared dimension | One row per pageview, per person |
| Growth | Distinct values x days | Traffic x retention window |
| Personal data | No - nobody can be picked out of a count | Yes, and directly identifiable |
| Subject requests | Out of scope | In scope; export and erase by your ID |

Segments are the one to reach for first. Most questions people think need
per-user tracking - "do members read more than guests", "which plan converts"
- are aggregate questions, and the aggregate answer is the one that survives
contact with a privacy review.

See also [Privacy & compliance](../privacy/README.md) and
[How visitors are counted](../privacy/how-counting-works.md).
