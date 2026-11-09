<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Своя сумма хранения выданной ТС (09.11.2026, владелец: «пока она стояла, ставка изменилась — им проще итоговую сумму
 * указать пересчитанную и всё»): за весь срок от приёма до выдачи, поверх расчёта по ставке (`Accrual::storage` делит её
 * по дням пропорционально). Пусто — по ставке. Снимается выбором «По ставке» в шторке «Хранение».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->decimal('storage_total', 12, 2)->nullable());
    }

    public function down(): void
    {
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->dropColumn('storage_total'));
    }
};
