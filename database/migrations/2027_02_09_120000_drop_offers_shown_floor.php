<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Закупочная — за каждым менеджером на момент его подтверждения (28.10.2026, владелец: 91720 — закупочную снизили
 * 529 206 → 333 333, а подтверждение Романа после этого взяло прежнюю из `offers.shown_floor`). Колонки больше нет:
 * подтверждение снимает нынешнюю закупочную. Не принятым подтверждениям, что сняли прежнюю после правки, — закупочная
 * на момент подтверждения по истории («Изменён: закупочная цена …»).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            update bids b set cost = (
                select (e.payload->'changes'->'floor_price'->>1)::bigint from offer_events e
                where e.offer_id = b.offer_id and e.type = 'updated' and e.payload->'changes'->'floor_price' is not null and e.created_at <= b.created_at
                order by e.id desc limit 1)
            from offers o
            where o.id = b.offer_id and o.shown_floor is not null and b.cost = o.shown_floor and b.state <> 'accepted'
              and exists (select 1 from offer_events e where e.offer_id = b.offer_id and e.type = 'updated' and e.payload->'changes'->'floor_price' is not null
                  and e.created_at <= b.created_at and (e.payload->'changes'->'floor_price'->>1) ~ '^[0-9]+$')
            SQL);
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('shown_floor'));
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->bigInteger('shown_floor')->nullable());
    }
};
