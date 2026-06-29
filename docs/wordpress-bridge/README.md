# WordPress Bridge: MIM Social Publisher → Social Cockpit

Deze map bevat de "brug" die ervoor zorgt dat ZTS-inzendingen via
`socialmedia.z-t-s.nl` automatisch ook in Social Cockpit landen.

## Installatie op de ZTS-server

### 1. Bestand uploaden

Plaats `class-cockpit-bridge.php` in de MIM Social Publisher plugin map:

```
wp-content/plugins/mim-social-publisher/includes/class-cockpit-bridge.php
```

### 2. Loader registreren

In `mim-social-publisher.php`, in de `load_includes()` methode toevoegen:

```php
require_once MIM_SP_PLUGIN_DIR . 'includes/class-cockpit-bridge.php';
```

En in `register_hooks()`:

```php
MIM_SP_Cockpit_Bridge::register();
```

### 3. Hook afvuren vanuit MIM_SP_Zip_Extractor::process()

Direct na de regel waar `create_social_post()` slaagt en media-koppeling klaar
is (vóór `MIM_SP_Caption_Generator::schedule($post_id)`), één regel toevoegen:

```php
do_action( 'mim_sp_social_post_created', $post_id, array_merge( $data, array(
    'attachment_ids' => $attachment_ids,
) ) );
```

### 4. Configuratie via WP-options

Eenmalig instellen via WP-CLI of een snelle PHP-snippet:

```bash
wp option update mim_sp_cockpit_webhook_url    "https://socials.burodebom.nl/api/webhook/content"
wp option update mim_sp_cockpit_webhook_secret "<JE_WEBHOOK_SECRET>"
```

(Beide options worden gelezen in `MIM_SP_Cockpit_Bridge::send`. Geen tokens
in code, alles in de database.)

Het secret moet **identiek** zijn aan `services.webhook.secret` in de Cockpit
(`config/services.php` / `.env: WEBHOOK_SECRET`).

## Wat de bridge doet

- Vuurt op `mim_sp_social_post_created` (action) één HTTP POST naar de Cockpit.
- Payload: `client=zts`, `title`, `brief`, `original_text`, `channels`,
  `media_urls[]` (publieke WP-URLs van de geüploade media), `external_id`.
- HMAC-SHA256 in header `X-Webhook-Signature`.
- Slaat een `_mim_sp_cockpit_sent`-postmeta op zodat dezelfde post nooit
  twee keer wordt doorgestuurd. De Cockpit doet daarnaast zelf nog
  idempotentie op `external_id`.

## Foutafhandeling

- WP_Error of niet-2xx response → wordt in `wp-content/debug.log` gelogd met
  prefix `[MIM_SP_BRIDGE]`. De MIM-flow loopt door, niets wordt geblokkeerd.
- Lege URL of lege secret → loggen + overslaan.

## Testen

Doe één inzending. Controleer:

1. WP debug.log bevat: `[MIM_SP_BRIDGE] Verzonden naar Cockpit (HTTP 201), social_post ID …`
2. In Social Cockpit (`/admin/`) verschijnt een Content Item voor ZTS met
   status Concept → na een paar seconden Gegenereerd.
