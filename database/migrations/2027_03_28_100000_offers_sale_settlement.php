<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Город продажи отдельно от города факта (14.11.2026, начальник: 624569/2026 везут из Новосибирска в Москву, продавать — в
 * Москве, а сменить город машины — сломать вывоз). `settlement_id` — где машина (вывоз, поставщик, источники),
 * `sale_settlement_id` — где продаём, его видит xcar.ru; пусто — совпадает (`Offer::saleCity`, как заявленная с закупочной).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->foreignId('sale_settlement_id')->nullable()->constrained('settlements')->nullOnDelete();
            $t->index('sale_settlement_id');
        });
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropConstrainedForeignId('sale_settlement_id'));
    }
};
