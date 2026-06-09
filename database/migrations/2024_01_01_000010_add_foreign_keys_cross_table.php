<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_articles', function (Blueprint $table) {
            $table->foreign('content_item_id')->references('id')->on('content_items')->nullOnDelete();
        });

        Schema::table('content_items', function (Blueprint $table) {
            $table->foreign('source_article_id')->references('id')->on('source_articles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropForeign(['source_article_id']);
        });

        Schema::table('source_articles', function (Blueprint $table) {
            $table->dropForeign(['content_item_id']);
        });
    }
};
