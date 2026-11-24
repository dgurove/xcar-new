<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Что просил поставщик (24.11.2026, владелец: «Цену решил Андрей Кузнецов — а какую, непонятно, неясно с чем сравнивать»): «Принять…»
 * с другой суммой перезаписывает `to_price` применённой, и строка «Т-Страхование просит N» после решения врала. Просьба — своей
 * колонкой `offer_reprices.asked_price`, её пишет `DetectReprice`; прошлое — из события заведения (`car_events`, `reprice`,
 * `state = pending`, `to`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_reprices', function (Blueprint $t) {
            $t->unsignedBigInteger('asked_price')->nullable();
        });
        DB::statement("update offer_reprices r set asked_price = e.asked from (
                select distinct on ((payload->>'reprice')::bigint) (payload->>'reprice')::bigint as id, (payload->>'to')::bigint as asked
                from car_events
                where type = 'reprice' and payload->>'state' = 'pending' and payload->>'reprice' ~ '^[0-9]+$' and payload->>'to' ~ '^[0-9]+$'
                order by (payload->>'reprice')::bigint, id
            ) e
            where e.id = r.id");
    }

    public function down(): void
    {
        Schema::table('offer_reprices', function (Blueprint $t) {
            $t->dropColumn('asked_price');
        });
    }
};
