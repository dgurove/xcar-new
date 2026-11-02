<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Грузовые и спецтехника — сами на оценку Корабельникову (02.11.2026, владелец): просьба, поставленная системой
 * (`offer_estimates.auto`, `AskHeavyEstimates`), и пять дней, пока машина открыта только ему (`offers.first_look_until`,
 * `FirstLook`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_estimates', fn (Blueprint $t) => $t->boolean('auto')->default(false));
        Schema::table('offers', fn (Blueprint $t) => $t->timestamp('first_look_until')->nullable());
    }

    public function down(): void
    {
        Schema::table('offer_estimates', fn (Blueprint $t) => $t->dropColumn('auto'));
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('first_look_until'));
    }
};
