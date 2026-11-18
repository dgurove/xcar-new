<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Суммы договора руками больше нет (18.11.2026, владелец: «они путают, что такое сумма договора и что такое цена продажи»; вписывали
 * в «сумму договора» цену продажи — у сделки Волкова 882 000, а цены не было, и счёт не вставал). Договор и счёт считаются сами
 * (`DealContract::priceFor`): цена продажи − вознаграждение − часть, отданная наличными мимо счёта (`cash_part`). Прежние ручные
 * суммы: цены нет — вписанная и была ценой; цена есть — разница уходит в наличные. Колонки `price_manual|price_by|price_at`
 * снимаем следующей выкладкой — во время этой их ещё читают старые копии сайта.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contracts', fn (Blueprint $t) => $t->integer('cash_part')->nullable());
        foreach (DB::table('deal_contracts as c')->join('deals as d', 'd.id', '=', 'c.deal_id')->where('c.price_manual', true)
            ->get(['c.id', 'c.price', 'c.deal_id', 'd.amount', 'd.commission']) as $c) {
            if ($c->price === null) {
                continue;
            }
            if ($c->amount === null) {
                DB::table('deals')->where('id', $c->deal_id)->update(['amount' => $c->price]);
            } elseif (($cash = (int) $c->amount - (int) $c->commission - (int) $c->price) > 0) {
                DB::table('deal_contracts')->where('id', $c->id)->update(['cash_part' => $cash]);
            }
        }
        DB::table('deal_contracts')->where('price_manual', true)->update(['price_manual' => false]);
    }

    public function down(): void
    {
        Schema::table('deal_contracts', fn (Blueprint $t) => $t->dropColumn('cash_part'));
    }
};
