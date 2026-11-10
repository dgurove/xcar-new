<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ответ менеджера на переоценку — его новая цена подтверждения (10.11.2026, владелец по 0790/046/09068/26: «где ввод его
 * новой цены подтверждения»): цена продажи до и после, его цена, наше контрпредложение.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reprice_asks', function (Blueprint $t) {
            $t->unsignedBigInteger('asking_from')->nullable();
            $t->unsignedBigInteger('asking_to')->nullable();
            $t->unsignedBigInteger('offered_price')->nullable();
            $t->timestamp('offered_at')->nullable();
            $t->unsignedBigInteger('counter_price')->nullable();
            $t->timestamp('counter_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reprice_asks', fn (Blueprint $t) => $t->dropColumn(['asking_from', 'asking_to', 'offered_price', 'offered_at', 'counter_price', 'counter_at']));
    }
};
