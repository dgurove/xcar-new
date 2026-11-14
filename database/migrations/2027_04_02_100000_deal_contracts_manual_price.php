<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сумма договора руками (14.11.2026, владелец: «админам и модераторам вручную удобно менять сумму договора и счёта, всякое
 * бывает»): `price_manual` — счёт идёт за `price`, сделка её не перетирает; кто и когда. Платёж поставщику из договора
 * комиссии Совкомбанка: назначенная цена (п. 2.4), назначение платежа и реквизиты получателя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contracts', function (Blueprint $t) {
            $t->boolean('price_manual')->default(false);
            $t->foreignId('price_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('price_at')->nullable();
            $t->integer('supplier_price')->nullable();
            $t->string('supplier_purpose', 500)->nullable();
            $t->jsonb('supplier_requisites')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deal_contracts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('price_by');
            $t->dropColumn(['price_manual', 'price_at', 'supplier_price', 'supplier_purpose', 'supplier_requisites']);
        });
    }
};
