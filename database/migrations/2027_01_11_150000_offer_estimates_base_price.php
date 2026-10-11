<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Корректировка цены продажи менеджерами (11.10.2026): админ цену поставил, но не уверен — просьба с его ценой
 * (`base_price`), менеджер «Согласен» или предлагает свою. Пусто — оценка черновика без цены, как была.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_estimates', function (Blueprint $t) {
            $t->unsignedBigInteger('base_price')->nullable()->after('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('offer_estimates', fn (Blueprint $t) => $t->dropColumn('base_price'));
    }
};
