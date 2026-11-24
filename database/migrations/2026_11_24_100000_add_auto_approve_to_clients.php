<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Wanneer true (default) plant de generatie automatisch in op het
            // eerstvolgende vrije slot. Bij false stopt het item op "in_review"
            // zodat een mens de tekst goedkeurt vóór publicatie. Voor ZTS staat
            // dit op false: ingezonden tekst kan een notitie zijn i.p.v. caption.
            $table->boolean('auto_approve')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('auto_approve');
        });
    }
};
