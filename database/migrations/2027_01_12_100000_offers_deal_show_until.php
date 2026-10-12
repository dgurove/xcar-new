<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Сколько показывать» сделку выбранным менеджерам (12.10.2026, владелец: модуль «Показ» одного вида у предложения и
 * сделки — у предложения срок приёма, у сделки срок показа). Пусто — пока идёт сделка.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->timestamp('deal_show_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->dropColumn('deal_show_until');
        });
    }
};
