<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 21.10.2026, владелец про Лобуса и Ананьева: «скрой им пока что закупочные цены, тумблер — показывать или нет, и пока не
 * показывай; пусть лучше продажные видят». Покупатель с гаражом видит свою цену и счёт только с галкой; без неё — цену
 * продажи предложения.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('garage_price_shown')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('garage_price_shown');
        });
    }
};
