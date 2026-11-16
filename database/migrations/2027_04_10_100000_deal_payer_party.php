<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Плательщик сделки — один на счёт и ссылку (16.11.2026, владелец: «это два способа оплаты одной суммы, а не два разных
 * платежа»; «у счёта тоже необязательно менеджер плательщик»). Пусто — как было: менеджер или покупатель по договору
 * (`deal_contracts.payer`). Своя сторона — кто угодно: покупатель, ИП, организация (`SetDealPayer`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('payer_party_id')->nullable()->after('buyer_party_id')->constrained('billing_parties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payer_party_id');
        });
    }
};
