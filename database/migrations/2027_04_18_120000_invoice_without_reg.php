<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Сформировать без прописки» (18.11.2026, владелец: начальник ждёт счёт, а менеджер не приложил данные): отметка у договора —
 * счёт за ТС встаёт без адреса прописки покупателя (`Party::invoiceGaps`), впишут — PDF покажет его сам. Договору прописка
 * нужна по-прежнему.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contracts', fn (Blueprint $t) => $t->timestamp('invoice_without_reg_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('deal_contracts', fn (Blueprint $t) => $t->dropColumn('invoice_without_reg_at'));
    }
};
