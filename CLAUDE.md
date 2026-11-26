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
   - Accepteert: `client`, `brief`, `title?`, `original_text?`, `channels?[]`, `media_urls?[]`, `external_id?`
   - `media_urls` worden gedownload naar `storage/app/public/media/{client-slug}/`
   - `external_id` zorgt voor idempotentie bij retries (één ContentItem per bron-ID per klant)
3. RSS via `FetchRssFeedsJob` (dagelijks)
4. Telegram bot (POST /api/webhook/telegram)

### Publishing
Gaat altijd via `PublisherInterface` → `PublerPublisher`. Nooit rechtstreeks naar LinkedIn/FB/Instagram.

### Goedkeuring (harde regel)
Er wordt NOOIT automatisch ingepland of gepubliceerd. `GenerateContentTextJob`
stopt altijd op `in_review` en stuurt de post via `SendTelegramReviewJob` naar
Telegram (media + tekst + knoppen ✅ Goedkeuren / ❌ Afwijzen). Goedkeuren kan
via die knop of via "Goedkeuren" in Filament (tab "Te beoordelen"); beide lopen
via `ApprovalService::approve()` → `ProcessAutoSchedulerJob::scheduleApproved()`
(gekozen tijd, anders het eerstvolgende vrije slot; alleen de kanalen van het
item). Een antwoord op het review-bericht past de tekst aan via OpenAI; begint
het met `tekst:` dan wordt de tekst letterlijk vervangen. Daarna volgt een nieuw
review-bericht; knoppen onder oudere berichten zijn ongeldig.
`social:review-digest` biedt elke maandag 08:00 alles wat nog wacht opnieuw aan.
De Telegram-webhook controleert `X-Telegram-Bot-Api-Secret-Token`
(`TELEGRAM_WEBHOOK_SECRET`, gezet via setWebhook). Bouw geen pad dat de
goedkeuring overslaat.

### Publer-API valkuilen
- `GET /posts` pagineert vanaf **page=0**. Beginnen bij 1 slaat de eerste 15
  (verst ingeplande) posts over → valse "0 van N posts"-alerts.
- `job_status` → `payload.failures` meldt per post "composer is in a bad state…
  missing the social network params" voor de accounts die NIET in die post
  zitten. Dat is ruis, geen kapotte koppeling. Verifieer altijd via de lijst.
- `social:verify-published` (uurlijks) zet een item pas op `geplaatst` als Publer
  de publicatie bevestigt, en alarmeert via Telegram als dat niet gebeurt.

### Queue
Alle externe API-calls (OpenAI, Publer, Telegram) via de Redis queue (productie: systemd-unit `social-cockpit-worker.service`, niet Horizon).
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

## ZTS-workflow

Inzendingen op socialmedia.z-t-s.nl worden door de WordPress-plugin **MIM Social
Publisher** (op die server) gePOST naar `/api/webhook/content` met `client=zts`,
`original_text`, `media_urls[]`, `external_id` en HMAC-handtekening (optioneel
`note`: opmerking aan buro_deBom, wordt opgeslagen als `source_note` en gaat
nooit de generatie in). De Cockpit downloadt de media, maakt een ContentItem
aan, draait `GenerateContentTextJob` (ZTS-tone-of-voice uit
`database/seeders/prompts/zts_tone_of_voice.md`) en zet het item op `in_review`.
Na handmatige goedkeuring wordt het ingepland op het eerstvolgende
dinsdag/vrijdag 07:30-slot. `SchedulePostToPublerJob` triggert
`SendTelegramPreviewJob` 24u voor publicatie. Replies in Telegram refinen de
tekst en updaten de bestaande Publer-post.

Let op: inzenders typen soms een notitie ("filmpje wilde niet uploaden") in het
tekstveld. Daarom is de reviewstap verplicht.

## OpenAI-tokenbeheer

Alle OpenAI-calls zitten in `app/Services/OpenAiService.php` (generate + refine,
aangeroepen vanuit `GenerateContentTextJob` en `TelegramWebhookController`).

Tokenoptimalisaties:
- Few-shot voorbeelden per generatie-call begrensd via `OPENAI_MAX_EXAMPLES`
  (default 4; was hardcoded 15).
- `refineText` (Telegram-edits) stuurt géén voorbeeldposts meer mee — de te
  bewerken tekst toont de stijl al. Trade-off: verfijning leunt op de
  tone-of-voice prompt + bestaande tekst; in de praktijk geen kwaliteitsverschil.
- Model configureerbaar via `OPENAI_MODEL` (default `gpt-4o`; `gpt-4o-mini`
  is ~15x goedkoper en te proberen zonder code-wijziging).
- Output begrensd via `OPENAI_MAX_OUTPUT_TOKENS` (default 600).
- Elk verzoek logt tokenverbruik naar laravel.log als
  `OpenAI tokenverbruik {purpose, content_item_id, model, prompt_tokens,
  completion_tokens, total_tokens}` — géén promptinhoud of keys. Monitoren:
  `grep "OpenAI tokenverbruik" storage/logs/laravel.log`.

De tone-of-voice prompt per klant (bv. ZTS) is bewust NIET ingekort: dat is
het kwaliteitsanker voor de schrijfstijl.

## Tests

`php artisan test` — 52 tests, SQLite in-memory. Covert: status transitions,
audit log, publish slot interval logica, webhook HMAC validatie, webhook media
+ originele tekst + idempotentie per `external_id`.

## Deployment

- Server: Hetzner VPS, 178.105.82.210
- Deploy via Laravel Forge
- Domein: socials.burodebom.nl
- Backups naar Backblaze B2 via `spatie/laravel-backup`
