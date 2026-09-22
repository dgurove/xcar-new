<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «По какому документу» у покупателя и «соответствует / не соответствует» при выдаче больше не спрашиваются:
 * документ получателя мы не храним, а если ТС выдали — значит соответствует. Отказ покупателя стал отдельной
 * кнопкой с причиной (событие `release_refused`), осмотра с отказом нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->dropColumn('pickup_note'));
        Schema::table('park_inspections', fn (Blueprint $t) => $t->dropColumn(['matches', 'mismatch_note', 'refused']));
    }

    public function down(): void
    {
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->string('pickup_note', 255)->nullable());
        Schema::table('park_inspections', function (Blueprint $t) {
            $t->boolean('matches')->nullable();
            $t->string('mismatch_note', 500)->nullable();
            $t->boolean('refused')->default(false);
        });
    }
};
