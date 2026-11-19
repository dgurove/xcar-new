<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Напомнить менеджеру» про отправленный счёт (19.11.2026, владелец): `nudged_at` — когда напомнили последний раз, `nudge_at` —
 * когда напомнить по плану («Напомнить через…»). Своё поле, а не `reminded_at`: тот ставят часы просрочки (`TickInvoices`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->timestamp('nudged_at')->nullable();
            $t->timestamp('nudge_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->dropColumn(['nudged_at', 'nudge_at']);
        });
    }
};
