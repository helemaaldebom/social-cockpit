<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Automatisch goedkeuren bestaat niet meer: élke post wacht op handmatige
    // goedkeuring. De per-klant-schakelaar is daarmee overbodig.
    public function up(): void
    {
        if (Schema::hasColumn('clients', 'auto_approve')) {
            Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('auto_approve'));
        }
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->boolean('auto_approve')->default(false)->after('active'));
    }
};
