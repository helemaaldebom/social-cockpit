<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Herlaadt de ZTS tone_of_voice met het anti-fabricatie-verbod. */
return new class extends Migration {
    public function up(): void
    {
        DB::table('clients')->where('slug', 'zts')->update([
            'tone_of_voice' => file_get_contents(database_path('seeders/prompts/zts_tone_of_voice.md')),
        ]);
    }
    public function down(): void {}
};
