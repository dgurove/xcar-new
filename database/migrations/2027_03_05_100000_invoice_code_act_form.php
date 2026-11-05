<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Закрытие месяца по архиву «СТОЯНКА» (05.11.2026): номер документа парковки — как в старых счетах и актах,
 * «ГГГГДДММ-NN» (`billing_invoices.code`), форма акта страховой (`vendors.act_form`, `Billing\ActForm`) и отдельный
 * договор эвакуации (у СОГАЗа — свой, № Э/2/23/3351).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->string('code', 20)->nullable()->after('number');
            $t->unique(['seller', 'code']);
        });
        Schema::table('vendors', function (Blueprint $t) {
            $t->string('act_form', 12)->nullable();
            $t->string('tow_agreement_number', 60)->nullable();
            $t->date('tow_agreement_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->dropColumn('code'));
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn(['act_form', 'tow_agreement_number', 'tow_agreement_date']));
    }
};
