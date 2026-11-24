<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Что ответил поставщик не письмом (24.11.2026, владелец: «написано, кто решил, а что решили — никак не посмотреть»): «Ответили не
 * письмом» у доп. просьбы — с необязательным «Что ответили» (`SettleExtraAsk::byPhone`), строкой в шаге и в «Доп. просьбах».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extra_asks', function (Blueprint $t) {
            $t->text('phone_answer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('extra_asks', function (Blueprint $t) {
            $t->dropColumn('phone_answer');
        });
    }
};
