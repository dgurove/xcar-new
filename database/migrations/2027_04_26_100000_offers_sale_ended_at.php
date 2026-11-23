<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Архив предложений менеджера (23.11.2026, начальник: «смотреть все предложения, что ушли за 30 дней, и написать админам по ним»):
 * когда предложение ушло из продажи (`open` → любое) — `offers.sale_ended_at`, его пишет `ChangeOfferState`, возврат в продажу
 * стирает. Прошлое — из истории: последний переход из `open` (`car_events`, `state_changed`). `deal_shown_until` — конец показа
 * опубликованной для других сделки, когда публикацию стёрли (`PublishDeal::clear`): прошлого не восстановить, пишется с выкладки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->timestamp('sale_ended_at')->nullable();
            // Конец показа опубликованной для других сделки, когда публикацию стёрли вместе со сделкой (`PublishDeal::clear`).
            $t->timestamp('deal_shown_until')->nullable();
        });
        DB::statement("update offers o set sale_ended_at = e.at from (
                select offer_id, max(created_at) as at from car_events
                where type = 'state_changed' and payload->>'from' = 'open' and offer_id is not null
                group by offer_id
            ) e
            where e.offer_id = o.id and o.state <> 'open'");
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->dropColumn(['sale_ended_at', 'deal_shown_until']);
        });
    }
};
