<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Договор подписан вне системы (14.11.2026, владелец: «договор уже подписан — тогда покупатель скип, реквизиты не нужны»):
 * `Deal::contractSigned` — покупателя и его данных не просим (`needsBuyer`, `AskBuyer`); ставят «Договор уже подписан» и
 * «Уже продана…» (`MarkContractSigned`, `MarkSoldDeal`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->timestamp('contract_signed_at')->nullable();
            $t->foreignId('contract_signed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->dropConstrainedForeignId('contract_signed_by');
            $t->dropColumn('contract_signed_at');
        });
    }
};
