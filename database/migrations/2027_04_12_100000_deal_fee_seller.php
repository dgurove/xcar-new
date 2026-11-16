<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кому платят услуги подбора — ООО «ПРАЙМ» или ООО «Р.О.С.» (16.11.2026, владелец: «Плательщик платит — выбор ооо прайм или
 * ооо р.о.с.», по аналогии с «Покупатель платит» у поставщика). Пусто — ПРАЙМ, как было (`Deal::feeSeller`, `SetFeeSeller`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('fee_seller', 8)->nullable()->after('payer_party_id');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('fee_seller');
        });
    }
};
