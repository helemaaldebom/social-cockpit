<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('brief');
            $table->text('generated_text')->nullable();
            $table->string('media_path')->nullable();
            $table->string('status')->default('concept');
            $table->dateTime('scheduled_for')->nullable();
            $table->string('publer_post_id')->nullable();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->unsignedBigInteger('source_article_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
