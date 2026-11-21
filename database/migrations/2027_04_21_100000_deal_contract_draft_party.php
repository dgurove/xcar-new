<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Новый покупатель — только при сохранении (21.11.2026, владелец: «Новый покупатель» в покупателях — «тупость»): плитка вида
 * («Физ. лицо», «ИП», «Юр. лицо») заводит не человека, а черновик данных у договора — контрагента без аккаунта, к нему ложится
 * скан паспорта. Человек встаёт в «Покупатели» менеджера, когда данные сохранили (`SaveDealContract`, `buyer_id = draft`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contracts', fn (Blueprint $t) => $t->foreignId('draft_party_id')->nullable()->constrained('billing_parties')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('deal_contracts', fn (Blueprint $t) => $t->dropConstrainedForeignId('draft_party_id'));
    }
};
