<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Сколько на счёт за ТС — вписывается (25.11.2026, владелец по 7806/046/03674/26: «по умолчанию на счёт закупочная, а должен быть
 * ввод цены, которая на счёт», «Р.О.С. 1 700 000, подбор ссылкой 160 000, менеджеру 40 000»). `car_price` пусто — умолчание
 * (`DealContract::priceFor`: Р.О.С. — закупочная, ПРАЙМ — цена − вознаграждение − наличные). Переключатель «подбор отдельной
 * ссылкой» (`deals.selection_apart`) — это «счёт ПРАЙМ = закупочная»: такие сделки получают её вписанной. Колонку `selection_apart`
 * не трогаем: её читают старые копии сайта во время выкладки и откат.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contracts', fn (Blueprint $t) => $t->integer('car_price')->nullable());
        foreach (DB::table('deals')->where('selection_apart', true)->whereNotNull('cost')->get(['id', 'cost', 'scheme']) as $d) {
            // Переключатель был только у счёта ПРАЙМ; у Р.О.С. закупочная — и так умолчание.
            if ($d->scheme !== 'ros') {
                DB::table('deal_contracts')->where('deal_id', $d->id)->update(['car_price' => $d->cost]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('deal_contracts', fn (Blueprint $t) => $t->dropColumn('car_price'));
    }
};
