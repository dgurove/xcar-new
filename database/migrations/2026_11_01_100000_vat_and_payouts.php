<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * НДС ПРАЙМ ставкой в счёте (УСН с НДС 5 %) вместо галки и константы 20 %: `vat_rate` пуст — прежняя
 * логика (счета парковки — бизнес другого лица, до своей задачи). «Сверху» или «в сумме» — у контрагента.
 * Сверка перечислений ЮMoney: попытка оплаты по ссылке помнит поступление, в которое вошла.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->unsignedSmallInteger('vat_rate')->nullable()->after('vat');
            $t->boolean('vat_on_top')->default(false)->after('vat_rate');
        });
        Schema::table('billing_parties', fn (Blueprint $t) => $t->boolean('vat_on_top')->default(false));
        Schema::table('billing_acquiring_payments', fn (Blueprint $t) => $t->foreignId('payout_tx_id')->nullable()->constrained('billing_bank_transactions')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('billing_acquiring_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('payout_tx_id'));
        Schema::table('billing_parties', fn (Blueprint $t) => $t->dropColumn('vat_on_top'));
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->dropColumn(['vat_rate', 'vat_on_top']));
    }
};
