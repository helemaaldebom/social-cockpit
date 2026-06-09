<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publish_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('day_of_week'); // 1=Monday, 7=Sunday
            $table->time('time');
            $table->string('timezone')->default('Europe/Amsterdam');
            $table->tinyInteger('interval_weeks')->default(1);
            $table->date('reference_date')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_slots');
    }
};
