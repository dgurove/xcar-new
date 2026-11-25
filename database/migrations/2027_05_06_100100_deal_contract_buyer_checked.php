<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Реквизиты покупателя проверены сотрудником (26.11.2026, владелец: «распознавание часто кривое, менеджеры отправляют как есть —
 * надо, чтобы Алина проверяла реквизиты»): кто, когда и какие данные (`buyer_checked_sum` — отпечаток полей и скана,
 * `DealContract::buyerSum`). Данные поменялись — проверка снова нужна.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contracts', function (Blueprint $t) {
            $t->timestamp('buyer_checked_at')->nullable();
            $t->foreignId('buyer_checked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('buyer_checked_sum', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deal_contracts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('buyer_checked_by');
            $t->dropColumn(['buyer_checked_at', 'buyer_checked_sum']);
        });
    }
};
