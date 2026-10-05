<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Оплата по ссылке до боя (владелец 05.10.2026: «непонятно, оплатили или нет»): у попытки — почему банк отказал и
 * последний возврат провайдера (его id и статус: «в обработке» не равно «вернули»); у ссылки — последняя ошибка
 * шлюза при «Оплатить», чтобы её видели в CRM, а не только в логе.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_acquiring_payments', function (Blueprint $t) {
            $t->string('cancel_reason', 48)->nullable()->after('status');   // cancellation_details.reason
            $t->string('refund_id', 64)->nullable()->after('refunded');
            $t->string('refund_status', 12)->nullable()->after('refund_id'); // pending | succeeded | canceled
        });
        Schema::table('billing_pay_links', function (Blueprint $t) {
            $t->string('error', 255)->nullable()->after('canceled_at');
            $t->timestamp('error_at')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('billing_acquiring_payments', fn (Blueprint $t) => $t->dropColumn(['cancel_reason', 'refund_id', 'refund_status']));
        Schema::table('billing_pay_links', fn (Blueprint $t) => $t->dropColumn(['error', 'error_at']));
    }
};
