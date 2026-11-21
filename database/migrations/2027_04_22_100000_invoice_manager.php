<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Чья ручная ссылка на оплату (22.11.2026, владелец: «в „Без менеджера“ — возможность менеджеров указывать и к сделке
 * существующей привязывать»): менеджер разовой оплаты без сделки. Менеджер счёта в «Оплатах» — сделка, засчитанная сделка,
 * гараж, иначе он (`MoneyController::MANAGER`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->dropConstrainedForeignId('manager_id'));
    }
};
