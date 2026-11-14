<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Платёж выписки → машина и её сделка (14.11.2026, владелец: «почему нигде не проставлены даты продажи и оплаты поставщику —
 * связь со СберБизнесом же есть»): `Billing\Bank\CarMatch` по VIN, номеру убытка, госномеру в назначении — и входящие, и
 * исходящие (поставщику). По ним — «Продано» и «Оплачено» сделки (`StampDealMoney`). `history` — строки выписки за прошлое
 * (`bank:history`): только для дат, без счетов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_bank_transactions', function (Blueprint $t) {
            $t->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $t->string('matched_by', 10)->nullable();
            $t->index('deal_id');
        });
        // Даты, вписанные руками («Продано» / «Оплачено» нажатием, `SetDealDate`): выписка их не перетирает.
        Schema::table('deals', fn (Blueprint $t) => $t->jsonb('dates_by_hand')->nullable());
    }

    public function down(): void
    {
        Schema::table('billing_bank_transactions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('deal_id');
            $t->dropColumn('matched_by');
        });
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('dates_by_hand'));
    }
};
