<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Счёт и договор сделки менеджеру — только кнопкой «Отправить менеджеру» (16.11.2026, владелец: «чтобы сотрудники могли проверить,
 * всё ли верно, перед тем как отправляется»). Пусто — у менеджера «Счёт готовится» / «Договор готовится». Прошлое не прячем:
 * счета сделок и готовые договоры — отправлены.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->timestamp('shown_at')->nullable();
            $table->foreignId('shown_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('deal_contracts', function (Blueprint $table) {
            $table->timestamp('shown_at')->nullable();
            $table->foreignId('shown_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::table('billing_invoices')->whereNotNull('deal_id')->update(['shown_at' => DB::raw('coalesce(issued_at, created_at)')]);
        DB::table('deal_contracts')->whereNotNull('ready_at')->update(['shown_at' => DB::raw('ready_at')]);
    }

    public function down(): void
    {
        foreach (['billing_invoices', 'deal_contracts'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropConstrainedForeignId('shown_by');
                $table->dropColumn('shown_at');
            });
        }
    }
};
