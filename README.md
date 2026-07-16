# Craft Analytics

Privacy-first, consent-aware analytics for Craft CMS 5. First-party,
GDPR-defensible, cookieless by default — with zero added time-to-first-byte
and database growth bounded by cardinality, not traffic.

- **No banner, real numbers.** Anonymous Tier-1 tracking uses a rotating daily
  salt and stores rollups only — no cookies, no device storage, no IP addresses,
  ever.
- **It knows your content.** Sections, entry types, authors and entries are
  first-class dimensions (Pro).
- **It won't hurt your site.** All capture happens after the response is
  flushed; storage is rollup-only with capped cardinality.

## Requirements

- Craft CMS ^5.0
- PHP ^8.2
- MySQL 8+ or PostgreSQL 13+
- Redis recommended (session hot layer + exact-ish unique counting), not required

## Installation

```bash
composer require coyshdigital/craft-analytics
php craft plugin/install craft-analytics
```

## Documentation

See [docs/](docs/) — installation, configuration reference, privacy &
compliance guide, developer/extension guide.

## Development

```bash
composer install
composer check-cs   # ECS (Craft ruleset)
composer phpstan    # PHPStan level 8
composer test       # Pest
```

A ddev-based Craft 5 dev harness lives in `dev/` — see `dev/README.md`.
