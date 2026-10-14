<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кто заказал перевозку (14.10.2026): продажа (вывоз CRM — `sale`) или парковка (перевод — `park`). Машина у перевозки
 * одна (`car_id`), но продажа той же машины в переводе между парковками не участвует: её шаги вывоза, условия «забирать
 * рано» и расход гаража — только у перевозки продажи. До переноса машин все перевозки были продажи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transports', fn (Blueprint $t) => $t->string('origin', 8)->default('sale'));
    }

    public function down(): void
    {
        Schema::table('transports', fn (Blueprint $t) => $t->dropColumn('origin'));
    }
};
