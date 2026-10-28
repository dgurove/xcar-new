<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Почему сделка закончилась (28.10.2026, владелец про 812865/2026: Совкомбанк отказался продавать нам, нашёл покупателя
 * сам — «это не в архив, а как завершённое по такой-то причине»): `close_reason` — `supplier` (отказ поставщика), пусто —
 * обычная отмена или продажа; `close_note` — причина словами. Ставит `Offers\Actions\DeclineBySupplier` и этап «Отказ
 * поставщика» маршрута. Прошлые сделки на этом этапе получают причину по нему.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->string('close_reason', 16)->nullable()->after('closed_at');
            $t->string('close_note', 500)->nullable()->after('close_reason');
        });
        DB::table('deals')->where('state', 'cancelled')->whereIn('offer_id', DB::table('offer_positions as p')->join('workflow_stages as s', 's.id', '=', 'p.stage_id')
            ->where('p.track', 'sale')->where('s.offer_state', 'cancelled')->select('p.offer_id'))
            ->update(['close_reason' => 'supplier']);
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn(['close_reason', 'close_note']));
    }
};
