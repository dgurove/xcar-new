<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Цена поставщика вписана, но не отправлена (08.11.2026, владелец: «когда они цены вписывают — автоматически не отправлять…
 * после того как они внесли закупочную цену — кнопка „Отправить“»): у нас предложение сразу в «Без продажной цены», у
 * поставщика оно остаётся в списке, пока он не нажмёт «Отправить» (`Offers\Partner\SendPrices`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->timestamp('partner_price_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('partner_price_at'));
    }
};
