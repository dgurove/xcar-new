<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Новый ДКП ПРАЙМ (14.11.2026, шаблон владельца): ПРАЙМ продаёт «за счёт и по поручению собственника» по договору комиссии —
 * его номер (`commission_no`, пусто — разобранный из файла или номер убытка вендора по комиссии) и собственник
 * (`seller_party_id`, теперь и у ПРАЙМ). НДС договора — выбором в CRM (`vat_rate`: 0, 5, 22; пусто — по ценам предложения).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contracts', function (Blueprint $t) {
            $t->string('commission_no', 40)->nullable();
            $t->smallInteger('vat_rate')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deal_contracts', fn (Blueprint $t) => $t->dropColumn(['commission_no', 'vat_rate']));
    }
};
