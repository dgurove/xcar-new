<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Наша доля навара по гаражу Совкомбанка — у каждого менеджера своя (12.11.2026, владелец; по Орлову — 60 %): от неё
 * вознаграждение при продаже (`Deal::suggestedCommission`). Пусто — 60.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->unsignedTinyInteger('garage_share')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('garage_share'));
    }
};
