<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * НДС поставщика ставкой, а не галкой (20.11.2026, бухгалтер: в счёте за ТС по договору комиссии — ставка того, у кого забираем
 * машину, на всю сумму). `offers.supplier_vat`: null — не знаем (счёт без НДС), 0 — без НДС, 22 — 22 %; откуда — `source`
 * (`human` | `letter` | `document` | `vendor`), письмо — `message_id`. Галка «С НДС» прежних → 22. У вендора — ставка по умолчанию.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->unsignedSmallInteger('supplier_vat')->nullable();
            $t->string('supplier_vat_source', 16)->nullable();
            $t->unsignedBigInteger('supplier_vat_message_id')->nullable();
            $t->timestamp('supplier_vat_at')->nullable();
        });
        Schema::table('vendors', function (Blueprint $t) {
            $t->unsignedSmallInteger('supplier_vat')->nullable();
        });
        DB::table('offers')->where('prices_include_vat', true)->update(['supplier_vat' => 22]);
        DB::table('vendors')->where('offers_include_vat', true)->update(['supplier_vat' => 22]);
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn(['supplier_vat', 'supplier_vat_source', 'supplier_vat_message_id', 'supplier_vat_at']));
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('supplier_vat'));
    }
};
