<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Werkt de ZTS tone_of_voice op de bestaande klant bij naar de laatste versie
 * met concrete formatregels (opener, bold namen, emoji-bullets voor specs,
 * lowercase hashtags met #zanentechniekservice eerst) en het GKBMV-voorbeeld
 * als gouden standaard.
 */
return new class extends Migration {
    public function up(): void
    {
        $prompt = file_get_contents(database_path('seeders/prompts/zts_tone_of_voice.md'));

        DB::table('clients')
            ->where('slug', 'zts')
            ->update(['tone_of_voice' => $prompt]);
    }

    public function down(): void
    {
        // Herstel niet mogelijk — de vorige exacte inhoud werd bij de
        // 2026_06_29 migratie overschreven. Bewust geen-op.
    }
};
