# Privacy and compliance

The headline claim is **"no banner, real numbers"**. This is how it holds up,
and exactly where it stops.

## The default: nothing to consent to

Out of the box the plugin is Lite-equivalent and cookieless. It sets no
cookie, uses no device storage, and stores no IP address anywhere - not in a
table, not in a log, not in a persistent cache key.

A visitor is identified for counting purposes by
`SHA-256(dailySalt ‖ ip ‖ userAgent ‖ siteId)`, truncated to 8 bytes. The salt
is 32 random bytes, rotated every 24 hours by default, and **the previous salt
is destroyed on rotation**. After that, yesterday's hash cannot be linked to
today's - by us, by the site owner, or by anyone holding the database.

That destruction is the whole argument. It is what makes the retained
statistics anonymous rather than pseudonymous, and it is why no consent banner
is engaged.

The cost is stated plainly elsewhere: multi-day unique-visitor figures are on
a [daily-unique basis](how-counting-works.md).

**Global Privacy Control** is honoured by default, and cannot be overridden -
not by a CMP, not by the site's own event handler. A signal you can overrule
is not a signal. `DNT` is supported too, off by default, because it is a dead
standard.

## Tier 2: consented tracking (Pro)

Everything below is **off by default**. Turning any of it on is a deliberate
act with compliance consequences, and the CP's Privacy panel says so.

| Setting | Default | What it changes |
|---|---|---|
| `enableConsent` | `false` | Allows a first-party cookie for visitors who agree. The banner-free posture ends here. |
| `enableJourneys` | `false` | Stores per-visitor page histories - real personal data. |
| `associateUserId` | `false` | Links analytics to Craft accounts. Being measured and being named are different agreements. |

### How consent is decided

In order of who wins:

1. **Consent must be switched on**, and the edition must be Pro. Otherwise the
   answer is "no" and no cookie is ever set. Gated in the service, not the UI.
2. **A browser privacy signal is absolute.** GPC means no, full stop.
3. **Our own cookie** - the record of an affirmative act via the JS API, a CMP
   adapter, or TCF.
4. **The site's CMP cookie** (`cmpCookieName`), read server-side. A value that
   isn't an explicit yes is treated as a **no**, not an unknown: the visitor
   has been asked.
5. **The `defineConsent` event**, which sees the resolved state and may change
   it - the site knows things we don't.

**Absence is never consent.** Unknown behaves exactly like a refusal.

### The cookie

`_ca_vid`: 128 random bits, `HttpOnly`, `Secure`, `SameSite=Lax`, 13 months by
default and **hard-capped at 24**. A consent someone gave two years ago and has
not seen since is not a consent worth relying on.

HttpOnly always, not "where possible" - `sendBeacon` sends cookies itself, so
nothing of ours needs to read it from JavaScript, and neither does anything
else. Craft signs it, so it cannot be forged.

### Withdrawal

Withdrawing deletes the cookie immediately and erases the visitor's stored
journeys.

**One subtlety worth knowing**, because it caught us: hits are spooled when
consent is live but written by the drain later. If someone withdraws in
between, erasing the database is not enough - the drain would write the
already-spooled hits back and quietly resurrect them. So the drain re-checks
the consent log at write time and drops hits belonging to anyone whose latest
decision is a refusal. There is a test for exactly this.

## The JS API

```js
craftAnalytics.consent('granted' | 'denied' | 'withdrawn')
craftAnalytics.gpcDetected   // true when the browser has opted out
```

`consent.js` is a separate file, loaded **only when consent is switched on** -
Lite sites and cookieless Pro sites download nothing for it, and `tracker.js`
stays small.

The server owns the decision; the script only relays it. The cookie is set
server-side so it can be HttpOnly and signed.

### CMP adapters

Optional, one file each, under `resources/js/adapters/`. Include after
`consent.js`:

| CMP | Maps from |
|---|---|
| Klaro | a `craft-analytics` service |
| Cookiebot | the `statistics` category |
| CookieYes | the `analytics` category |
| Osano | `ANALYTICS === 'ACCEPT'` |
| Civic Cookie Control | an `analytics` category |
| IAB TCF v2.2 | purposes **1 and 8**, both required |

TCF requires both purposes because they are different permissions: purpose 8
without purpose 1 is consent to be measured but not to be given a cookie -
which is Tier-1, not Tier-2.

## Data subject rights

```bash
php craft craft-analytics/privacy/export --visitor-id=<id>
php craft craft-analytics/privacy/export --user-id=42
php craft craft-analytics/privacy/erase  --visitor-id=<id>
```

**Aggregate rollups are out of scope, and this matters.** They are anonymous
statistical data: counters and probabilistic sketches with no individual
inside them to find or remove. A HyperLogLog sketch cannot be interrogated for
a person, and the salted hashes that fed it stopped being linkable the moment
the salt rotated.

So a subject request touches exactly two things: the consented journeys layer
and the consent log. On a default install, both are empty - there is nothing
to export, and that is the point.

**Erasure keeps the consent record by default.** It is the evidence that the
now-erased processing was lawful; destroying it on request would leave the site
unable to answer the first question a regulator asks. `--include-consent-log`
removes it too when the site's legal position calls for that.

## Evidence

Every consent decision is logged with its timestamp, state, method, scope and
**policy version** - so bumping `policyVersion` when your notice changes means
old consents continue to evidence the policy they were given under.

The log is pseudonymous by construction: a random visitor ID, or the rotating
Tier-1 hash for a refusal recorded before any ID existed. Nothing in it names
anybody. A GPC visitor gets no row at all - writing one for somebody who asked
not to be tracked would be perverse.

## The paperwork

```bash
php craft craft-analytics/privacy/document --to=./privacy
```

Generates three Markdown drafts from the **live configuration**:

- `ropa.md` - a Record of Processing Activities entry (Art. 30)
- `privacy-notice-appendix.md` - plain English for visitors to read
- `dpia-summary.md` - the facts for an assessment, and an honest read on
  whether one is required

They are accurate about what the software does - the part a lawyer cannot know
and usually has to guess at. They are **not legal advice**, they say so, and
somebody qualified should review them before you rely on them. Regenerate them
whenever the settings change.

## The privacy panel

**Analytics → Privacy** in the CP reports what your configuration *permits* -
not what happens to have occurred, because "we could set a cookie" is the
compliance-relevant fact. It warns when a setting undermines the banner-free
claim, when GPC is being ignored, when the salt rotates too slowly, and when
the consent cookie outlives 13 months.
