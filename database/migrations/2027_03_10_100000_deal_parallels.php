<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Параллельная сделка (07.11.2026, 781R/046/01529/26 — Прадо у Тужикова и у Зайцева): строка `deals` в состоянии
 * `parallel` со своим «теневым» шагом маршрута (`shadow_stage_id`, `shadow_at`) — маршрут, письма и счета у машины одни,
 * у главной сделки. `reissue_at` — главную сделку переключили после письма с реквизитами: письмо поставщику — «перевыставить
 * счёт». Живая сделка у человека по машине одна: главная или параллельная.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->foreignId('shadow_stage_id')->nullable()->constrained('workflow_stages')->nullOnDelete();
            $t->timestamp('shadow_at')->nullable();
            $t->timestamp('reissue_at')->nullable();
        });
        DB::statement("create unique index deals_one_live_per_buyer on deals (offer_id, buyer_id) where state in ('active', 'parallel') and buyer_id is not null");
    }

    public function down(): void
    {
        DB::statement('drop index if exists deals_one_live_per_buyer');
        Schema::table('deals', function (Blueprint $t) {
            $t->dropConstrainedForeignId('shadow_stage_id');
            $t->dropColumn(['shadow_at', 'reissue_at']);
        });
    }
};
