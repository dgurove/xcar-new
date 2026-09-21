<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Интери (interi-sk.ru): «Передача ТС … на стоянку С2500291» — 49 писем в истории без заказчика. */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('vendors')->get()->first(fn ($v) => in_array('interi-sk.ru', (array) json_decode($v->senders ?: '[]', true), true) || $v->name === 'Интери');
        if (! $exists) {
            DB::table('vendors')->insert(['name' => 'Интери', 'senders' => json_encode(['interi-sk.ru']), 'parser' => 'generic', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
