# mypinacle/tracking-sdk

Shared server-side tracking for MyPinacle-Ecom and MyPinacle-Inventory (Inventory serves the Epetzo storefront API).
Laravel 10, PHP ^8.1. The service provider is auto-discovered.

## What is in it
| Path | Purpose |
|---|---|
| `src/Graph.php` | Meta Graph API version (`v26.0`) + `url()` / `webUrl()` for every Meta call (CAPI, FB login, WhatsApp) |
| `src/Platforms/` | Meta Conversions API, TikTok Events API v1.3, GA4 Measurement Protocol payload builders |
| `src/Support/Hashing.php` | SHA-256 normalisation (email, phone, names, city, country, external_id) + Google Enhanced Conversions |
| `src/Support/Country.php` | any country code / ISO3 / name → lowercase ISO-2, unknown → null (field omitted) |
| `src/Support/EventIds.php` | signed server event ids `{prefix}_{body}_{sig}`; deterministic per (event, nonce, scope) for retries |
| `src/Ledger/` | send-once ledger: `tracking_event_claims` unique (channel, event_id), cache fallback |
| `src/ServerEvents.php` | `issueId()`, `send()` (browser events), `purchase()` (one per order), `browserPurchase()` |
| `src/TrackingContext.php` | consent + pixel cookies / click ids (from cookies or a headless request body) |
| `src/Contracts/` | what each app provides: `TrackingSettings` (DB-read keys), `TrackingTransport` (job + marketing_events), `EventLedger` |

Each app binds `TrackingTransport` to its own `App\Support\Tracking\QueueTransport` and passes its own `TrackingSettings`.
Keys are only ever read from the database by the app adapters.

Tests: `composer install && php -d extension=pdo_sqlite vendor/bin/phpunit`

## Install
The repository is public, so no credentials, deploy keys or auth.json are needed. In the app's composer.json:
```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/Anikreza/MyPinacle-Tracking-SDK"}
]
```
then `composer require mypinacle/tracking-sdk:^1.0`. For an app that shares the database, run the `tracking_event_claims`
migration line from Inventory `run-migrations.txt` once.

Release: tag `v1.x.y`, push the tag, then `composer update mypinacle/tracking-sdk` in each app.
(Registering the package on Packagist would make the `repositories` entry unnecessary.)