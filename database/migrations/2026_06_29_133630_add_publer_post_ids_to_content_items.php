<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Een bulk-schedule call bij Publer geeft één job_id, maar die job maakt
 * meerdere posts aan (één per netwerk). Voor updates/deletes moet je het
 * échte post-ID per netwerk hebben — niet het job-ID. We slaan ze nu als
 * JSON-array op. publer_post_id blijft staan voor leesbaarheid (= eerste ID).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->json('publer_post_ids')->nullable()->after('publer_post_id');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn('publer_post_ids');
        });
    }
};
