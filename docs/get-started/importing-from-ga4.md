---
title: Importing from GA4
description: Bring your history across from Google Analytics 4 so the reports do not start empty.
---

# Importing from GA4

Craft Analytics starts counting the day you install it. If you are moving over
from Google Analytics 4, the reports look empty for the first few weeks while
they fill up, and the switch feels like a step backwards. This utility fixes
that: it connects to your own Google account, reads the history GA4 already
holds, and writes it into your analytics so the reports carry on unbroken across
the changeover.

You will find it under **Utilities → Import GA4 History**.

## What it does, and does not, do

- It only ever **reads** what Google already has. Nothing about your visitors is
  sent to Google at any point.
- It makes **no Google call on its own initiative**. Every request happens
  because you clicked something or started an import; nothing runs on a schedule.
- It writes **daily aggregates**. GA4 gives totals per day, not the individual
  visits behind them, so:
  - **Views, sessions and events** come across exactly as GA4 reported them.
  - **Unique visitor counts are approximate** for imported days. GA4 gives a
    count, not the visitors, so the plugin seeds each day to about the same
    figure; it cannot be exact, and it is labelled as an estimate on screen.
- **Days you have already tracked are left untouched.** Only days with no data
  yet are imported, so the seam where the plugin took over never double-counts.
- On **Lite**, pages, sessions, sources and devices are imported. Campaigns,
  geography and events need **Pro**.

## Connecting to Google

Google will not let anything read your analytics until you tell it this site is
allowed to ask. That is what the two values you create below are: a name and a
password that identify this site to Google, and nothing else. It takes about
five minutes, costs nothing, and asks for no card.

The utility walks you through these same steps; they are here for reference.

1. **Copy the redirect address** the utility shows in step 1. Google asks for it
   later and matches it exactly, so copy it rather than typing it.
2. **Make a Google project.** Open the
   [Google Cloud console](https://console.cloud.google.com/) and sign in with
   the Google account that can already see the analytics you want to bring
   across. Create a project; the name is only for you, and no billing is
   involved.
3. **Enable the two APIs**, in the same project:
   [Google Analytics Admin API](https://console.cloud.google.com/apis/library/analyticsadmin.googleapis.com)
   (so your property list loads) and
   [Google Analytics Data API](https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com)
   (so the import can run).
4. **Create the sign-in details.** Fill in the consent screen when the console
   asks. If your account belongs to a Workspace, choose Internal; otherwise
   choose External and add your own address as a test user. Then, under
   Credentials, create an OAuth client ID. Choose **Web application**, not
   Desktop, and under Authorised redirect URIs paste the address from step 1.
   Keep the Client ID and Client secret it gives you.
5. **Add them to the plugin.** Paste the Client ID and Client secret into the
   GA4 import fields on the plugin's settings page and save.

### Keeping the secret out of project config

The Client ID and Client secret are ordinary plugin settings, so a value pasted
into the settings page is written to your project config, which usually deploys
with the site. The secret does not belong there. Use an environment variable
instead: set `CRAFT_ANALYTICS_GA4_CLIENT_ID` and
`CRAFT_ANALYTICS_GA4_CLIENT_SECRET` in your `.env`, and either reference them in
the settings fields as `$CRAFT_ANALYTICS_GA4_CLIENT_ID` and
`$CRAFT_ANALYTICS_GA4_CLIENT_SECRET`, or set them in
`config/craft-analytics.php`:

```php
use craft\helpers\App;

return [
    'ga4ClientId' => App::env('CRAFT_ANALYTICS_GA4_CLIENT_ID') ?: null,
    'ga4ClientSecret' => App::env('CRAFT_ANALYTICS_GA4_CLIENT_SECRET') ?: null,
];
```

The tokens Google issues after you connect are always stored encrypted in the
database, never in project config, and are deleted when you disconnect.

## Running an import

Once you are connected:

1. Choose the **GA4 property** to import from.
2. Choose the **Craft site** to import into.
3. Choose the **date range**. The range can be as far back as GA4 will report.
4. Choose **what to import**, then start it.

The import runs in the background on Craft's queue. Follow its progress under
**Utilities → Queue Manager**. When it finishes, the imported days appear across
the Dashboard, Pages, Sources, Devices and (on Pro) Campaigns, Geography and
Events reports.

Re-running an import is safe: it skips every day that already has data,
including days an earlier run brought across.

## When Google says it has not verified this app

While your OAuth client is in testing, Google shows a warning that the app is
unverified before it lets you continue. This is expected for an app only you
use, and you do not need to submit it for verification. Choose to continue (the
exact wording is usually "Go to _your project_ (unsafe)"), as long as the
project is the one you just created. Adding your own address as a test user in
step 4 is what makes this available.

## Troubleshooting

- **The property list is empty.** The Admin API is not enabled on the project,
  or you signed in with a Google account that cannot see the property.
- **The import stops as soon as it starts.** The Data API is not enabled on the
  project.
- **"Google did not return a refresh token."** Disconnect, then connect again
  and approve the access. Google only returns a refresh token on a fresh
  approval.
