# Social Cockpit — CLAUDE.md

## Stack

Laravel 12, PHP 8.3+, Filament 3, MySQL 8.4, Redis, Laravel Horizon.

## Lokale database

- MySQL draait via MAMP op poort **8889**
- Credentials: root / root
- Database: `social_cockpit`

## Architectuur

### Content-pijplijn
ContentItems volgen een expliciete state machine via `ContentItem::changeStatus()`.
Geldige overgangen: concept → gegenereerd → in_review → goedgekeurd → ingepland → geplaatst.
Elke overgang naar `mislukt` is altijd geldig. Elke overgang wordt gelogd in `content_item_logs`.

### Inlaten
1. Handmatig via Filament admin
2. Webhook POST /api/webhook/content (HMAC-SHA256 via X-Webhook-Signature header)
3. RSS via `FetchRssFeedsJob` (dagelijks)
4. Telegram bot (POST /api/webhook/telegram)

### Publishing
Gaat altijd via `PublisherInterface` → `PublerPublisher`. Nooit rechtstreeks naar LinkedIn/FB/Instagram.

### Queue
Alle externe API-calls (OpenAI, Publer, Telegram) via Redis queue + Horizon.
Jobs: `GenerateContentTextJob`, `SchedulePostToPublerJob`, `SendTelegramPreviewJob`, `UpdatePublerPostJob`, `FetchRssFeedsJob`, `ProcessAutoSchedulerJob`.

## Klanten bij start

- ZTS (Facebook, Instagram, LinkedIn) — dinsdag + vrijdag 07:30
- Landus (LinkedIn) — donderdag 10:00 tweewekelijks
- buro_deBom (Facebook, Instagram, LinkedIn) — slot inactive, RSS-feeds actief
- Bas Romeijn (LinkedIn) — slot inactive

## Beveiliging

- `blocked_ips` tabel voor IP-blokkade na 10 mislukte logins
- `CheckBlockedIp` middleware op alle admin routes
- `ValidateWebhookSignature` middleware op webhook endpoint
- TOTP 2FA via `pragmarx/google2fa-laravel`, beheer via `/admin/two-factor-setup`

## Tests

`php artisan test` — 13 tests, SQLite in-memory. Covert: status transitions, audit log, publish slot interval logica, webhook HMAC validatie.

## Deployment

- Server: Hetzner VPS, 178.105.82.210
- Deploy via Laravel Forge
- Domein: socials.burodebom.nl
- Backups naar Backblaze B2 via `spatie/laravel-backup`
