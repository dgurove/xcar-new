<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Когда предложение попало в свою вкладку CRM (15.10.2026, владелец: оценил закупочную — и ищи машину внизу «Без продажной»,
 * список шёл по заведению). Прошлым — время последнего события, что сменило шаг (состояние, закупочная, продажная, слот,
 * аукцион), иначе заведение.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->timestamp('tab_at')->nullable()->index();
        });
        DB::statement(<<<'SQL'
            update offers o set tab_at = coalesce((
                select max(e.created_at) from offer_events e
                where e.offer_id = o.id and (e.type = 'state_changed' or (e.type = 'updated'
                    and jsonb_exists_any(coalesce(e.payload::jsonb -> 'fields', '[]'::jsonb), array['floor_price', 'asking_price', 'slot_at', 'auction_at'])))
            ), o.created_at, now())
        SQL);
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('tab_at'));
    }
};
