<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            // Opmerking van de inzender aan buro_deBom (bv. "filmpje wilde niet
            // uploaden"). Puur ter informatie — gaat NOOIT de OpenAI-generatie
            // in en wordt nooit gepubliceerd.
            $table->text('source_note')->nullable()->after('original_text');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn('source_note');
        });
    }
};
