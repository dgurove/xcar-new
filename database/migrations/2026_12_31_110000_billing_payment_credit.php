<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Зачёт разовой оплаты по ссылке в счёт сделки (09.10.2026, владелец: «пусть это будет зачёт суммы сделки, а сверх —
 * доплата»): оплата вида `credit` по счёту сделки, `credit_invoice_id` — какой ручной счёт засчитан (`ApplyDealCredits`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payments', function (Blueprint $t) {
            $t->foreignId('credit_invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
            $t->index('credit_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('billing_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('credit_invoice_id'));
    }
};
