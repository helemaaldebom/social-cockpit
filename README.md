# Social Cockpit — buro_deBom

Intern social media platform voor het beheren van content voor meerdere klanten, van idee tot publicatie via Publer.

## Stack

- Laravel 12 + PHP 8.3+
- Filament 3 (admin panel)
- MySQL 8.4
- Redis (queue + cache)
- Laravel Horizon (queue monitoring)
- OpenAI API (tekstgeneratie)
- Publer Business API (scheduling/publicatie)
- Telegram Bot API (previews, correcties, foutmeldingen)
- spatie/laravel-backup → Backblaze B2

## Installatie (lokaal)

```bash
composer install
cp .env.example .env
php artisan key:generate
# Stel DB en andere env vars in
php artisan migrate
php artisan db:seed
```

## Vereiste environment variables

```env
# App
APP_KEY=...
APP_URL=https://socials.burodebom.nl

# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=social_cockpit
DB_USERNAME=...
DB_PASSWORD=...

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# OpenAI
OPENAI_API_KEY=sk-...

# Publer
PUBLER_API_KEY=...

# Telegram
TELEGRAM_BOT_TOKEN=...
TELEGRAM_CHAT_ID=...

# Webhook (HMAC secret)
WEBHOOK_SECRET=...

# Backblaze B2 backups
B2_KEY_ID=...
B2_APPLICATION_KEY=...
B2_BUCKET=...
B2_REGION=...

# Wachtwoord voor admin seeder
ADMIN_PASSWORD=...
```

## Queue en Horizon

Start Horizon voor queue-monitoring:

```bash
php artisan horizon
```

Horizon dashboard bereikbaar op `/horizon` (alleen voor authenticated admins).

## Scheduler

Voeg deze crontab toe op de server:

```
* * * * * cd /var/www/socials.burodebom.nl && php artisan schedule:run >> /dev/null 2>&1
```

Draait:
- `social:fetch-rss` — dagelijks 06:00
- `social:process-scheduler` — elk uur
- `backup:run` — dagelijks 03:00
- `horizon:snapshot` — elke 5 minuten

## Artisan commands

```bash
# Publer accounts synchroniseren met kanalen
php artisan social:sync-publer-accounts

# Auto-scheduler handmatig triggeren
php artisan social:process-scheduler

# RSS handmatig fetchen
php artisan social:fetch-rss
```

## Telegram webhook setup

Registreer de webhook bij BotFather:

```
https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://socials.burodebom.nl/api/webhook/telegram
```

Alleen berichten van de ingestelde `TELEGRAM_CHAT_ID` worden verwerkt.

## Webhook voor klantformulier (ZTS)

```
POST /api/webhook/content
Header: X-Webhook-Signature: sha256=<hmac-sha256 van de body met WEBHOOK_SECRET>
Content-Type: application/json

{
  "client": "zts",
  "brief": "Tekst of omschrijving van de post",
  "title": "Optionele titel",
  "channels": ["linkedin", "facebook"]  // optioneel
}
```

## Backups (Backblaze B2)

Backups draaien via `spatie/laravel-backup`. Configureer B2 env vars.
Handmatig draaien:

```bash
php artisan backup:run
php artisan backup:list
```

## 2FA instellen

Na inloggen, ga naar `/admin/two-factor-setup`. Scan de QR-code met Microsoft Authenticator of vergelijkbare TOTP-app.

## Forge/deployment aandachtspunten

- `APP_DEBUG=false` in productie
- MySQL bereikbaar via localhost, niet publiek
- SSL via Let's Encrypt in Forge
- Horizon starten als daemon via Forge worker: `php artisan horizon`
- Scheduler instellen via Forge scheduler
- Storage linken: `php artisan storage:link`

## Beveiliging

- Filament admin achter 2FA (TOTP)
- Sessie verloopt na 120 minuten inactiviteit
- Rate limiting: max 5 loginpogingen/minuut per IP
- Na 10 mislukte pogingen in 1 uur: IP 24 uur geblokkeerd + Telegram-melding
- HMAC-signature validatie op webhook endpoints
- Security headers: HSTS, X-Frame-Options, X-Content-Type-Options

## Tests

```bash
php artisan test
```
