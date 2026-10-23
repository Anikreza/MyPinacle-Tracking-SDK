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

## Local development (path repository)
Both apps' composer.json contain:
```json
"repositories": [{"type": "path", "url": "../MyPinacle-Tracking-SDK",
  "options": {"symlink": true, "versions": {"mypinacle/tracking-sdk": "1.0.0"}}}],
"require": {"mypinacle/tracking-sdk": "^1.0"}
```
A path repository only works where `../MyPinacle-Tracking-SDK` exists, so do not deploy a lock file built from it.

## Switch to the private GitHub repo (before deploying)
1. `gh repo create Anikreza/MyPinacle-Tracking-SDK --private`, then in this folder:
   `git remote add origin https://github.com/Anikreza/MyPinacle-Tracking-SDK.git && git push -u origin main && git tag v1.0.0 && git push origin v1.0.0`
2. In each app replace the repository entry with
   `{"type": "vcs", "url": "https://github.com/Anikreza/MyPinacle-Tracking-SDK.git"}`
   then `composer update mypinacle/tracking-sdk` and commit composer.json + composer.lock.
3. Each server needs read access. Pick one:
   - **Deploy key (SSH):** on the server `ssh-keygen -t ed25519 -f ~/.ssh/mypinacle_sdk -N ""`, add the `.pub` under
     GitHub repo Settings → Deploy keys (read-only). Add a `~/.ssh/config` entry
     `Host github.com` / `IdentityFile ~/.ssh/mypinacle_sdk` / `IdentitiesOnly yes` (GitHub allows a deploy key on only
     one repo; with several repos use a `Host github-sdk` alias and that host in the vcs url).
   - **auth.json (HTTPS):** a fine-grained token with Contents: read on this repo only, and in the app root (not committed)
     `{"github-oauth": {"github.com": "<token>"}}` — or `composer config --auth github-oauth.github.com <token>`.
     Then use the `https://github.com/Anikreza/MyPinacle-Tracking-SDK.git` url.
4. Deploy: `composer install --no-dev`, then run the `tracking_event_claims` migration line from Inventory `run-migrations.txt`
   (one shared database, run once).
5. Release: bump, tag `v1.x.y`, `composer update mypinacle/tracking-sdk` in each app.
