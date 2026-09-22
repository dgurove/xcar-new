<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расчёты с менеджерами: агентское вознаграждение на сделке (снимок закупочной,
 * сумма, режим), заявленные менеджером оплаты, карта у контрагента-человека.
 * Само вознаграждение к выплате — обычный `owed`-счёт `agent_fee`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->unsignedInteger('cost')->nullable();                     // закупочная в момент принятия
            $t->unsignedInteger('commission')->nullable();               // агентское вознаграждение
            $t->string('commission_mode', 8)->default('payout');         // payout | withheld
        });
        Schema::table('billing_payments', function (Blueprint $t) {
            $t->string('state', 9)->default('confirmed');               // confirmed | claimed | rejected
            $t->string('reject_reason', 255)->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->index(['state', 'invoice_id']);
        });
        Schema::table('billing_parties', fn (Blueprint $t) => $t->string('card', 19)->nullable());
    }

    public function down(): void
    {
        Schema::table('billing_parties', fn (Blueprint $t) => $t->dropColumn('card'));
        Schema::table('billing_payments', function (Blueprint $t) {
            $t->dropIndex(['state', 'invoice_id']);
            $t->dropColumn(['state', 'reject_reason', 'decided_at']);
        });
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn(['cost', 'commission', 'commission_mode']));
    }
};
