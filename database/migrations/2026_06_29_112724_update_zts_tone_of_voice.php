<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stelt de definitieve ZTS-tone-of-voice in op de bestaande klant.
 * De prompt staat in een los .md-bestand zodat seeder en migratie dezelfde
 * bron lezen en de tekst leesbaar te onderhouden blijft.
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
        DB::table('clients')
            ->where('slug', 'zts')
            ->update([
                'tone_of_voice' => 'Je bent een social media copywriter voor ZTS, een technisch servicebedrijf. Schrijf professionele, heldere posts die de expertise en betrouwbaarheid van het bedrijf uitstralen. Gebruik een zakelijke maar toegankelijke toon.',
            ]);
    }
};
