<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Twee uitbreidingen op content_items:
 *  - media_paths: JSON-array voor meerdere media-bestanden per post (carrousel).
 *    media_path blijft staan voor backward compatibility (de eerste entry).
 *  - original_text: originele klanttekst, los van generated_text (de AI-versie).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->json('media_paths')->nullable()->after('media_path');
            $table->text('original_text')->nullable()->after('brief');
        });

        // Backfill: bestaande items met media_path → media_paths = [media_path]
        DB::statement(<<<'SQL'
            UPDATE content_items
            SET media_paths = JSON_ARRAY(media_path)
            WHERE media_path IS NOT NULL AND media_paths IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn(['media_paths', 'original_text']);
        });
    }
};
