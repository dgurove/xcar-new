<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Разовая оплата по ссылке с машиной (09.10.2026, владелец): ТС системы — `offer_id`, ТС не из системы — `car_title`;
 * `credit_deal_id` — сделка, в которую оплата засчитывается (`SyncDealInvoices` выставляет на оплаченное меньше).
 * `deal_id` у ручного счёта не ставится: по нему живут счета самой сделки. У ссылки — `auto`: завела система вместе со
 * счётом (`EnsurePayLink`), а не человек; у прежних — по тому, что она появилась вместе со счётом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->string('car_title', 160)->nullable();
            $t->foreignId('credit_deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $t->index('credit_deal_id');
        });
        Schema::table('billing_pay_links', fn (Blueprint $t) => $t->boolean('auto')->default(false));
        DB::statement(<<<'SQL'
            update billing_pay_links l set auto = true
            from billing_invoices i
            where i.id = l.invoice_id and l.created_at <= i.created_at + interval '10 seconds'
            SQL);
    }

    public function down(): void
    {
        Schema::table('billing_pay_links', fn (Blueprint $t) => $t->dropColumn('auto'));
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('credit_deal_id');
            $t->dropColumn('car_title');
        });
    }
};
