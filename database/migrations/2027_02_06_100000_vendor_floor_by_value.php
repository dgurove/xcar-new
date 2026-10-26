<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Галка «Ставка от оценочной стоимости» на парковке ни на что парковочное уже не влияла (ставку по стоимости даёт
 * лестница прайса, `park_tariffs.from_value`), а CRM читала её как «закупочная от оценочной» (26.10.2026, приборка):
 * колонка зовётся по смыслу, галка — в шторке «Продажа» CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', fn (Blueprint $table) => $table->renameColumn('rate_by_value', 'floor_by_value'));
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $table) => $table->renameColumn('floor_by_value', 'rate_by_value'));
    }
};
