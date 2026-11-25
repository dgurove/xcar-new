<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Для менеджера сделка закрыта (25.11.2026, владелец по 891799/2026: «для Армена всё — сделка закрыта считай, оплата поставщику —
 * наша задача»): `deals.manager_done_at` — у менеджера в ней ничего не осталось, впереди только наши шаги. Ставит и снимает
 * `Deal::syncManagerDone`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->timestamp('manager_done_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('manager_done_at'));
    }
};
