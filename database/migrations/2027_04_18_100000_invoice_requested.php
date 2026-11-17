<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Счёт на комиссию — только по просьбе (18.11.2026, владелец: «счёт на комиссию мы больше не формируем, только либо по ссылке
 * либо наличкой»; «ОООЧЕНЬ редко просят счёт» — «Запросить счёт…»). Когда и кто запросил: у подбора ПРАЙМ без отметки нет
 * ни номера, ни PDF (`Invoice::isDocument`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->timestamp('requested_at')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('requested_by');
            $t->dropColumn('requested_at');
        });
    }
};
