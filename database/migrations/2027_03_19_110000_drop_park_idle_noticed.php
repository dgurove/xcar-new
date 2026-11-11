<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «Стоит дольше 30 дней» снято (11.11.2026, владелец: «халявные бабки капают, это не проблема») — отметка не нужна.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->dropColumn('idle_noticed_at'));
    }

    public function down(): void
    {
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->timestamp('idle_noticed_at')->nullable());
    }
};
