<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('network')->default('linkedin'); // linkedin, facebook, instagram
            $table->text('content'); // de voorbeeldpost tekst
            $table->string('label')->nullable(); // optioneel label, bv. "Productlancering" of "Klantcase"
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_examples');
    }
};
