<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заявленная на оценке — кому показать, кому нет (11.10.2026, владелец): админ решает за каждого менеджера в шторке
 * «Отдать на оценку». Прежние просьбы — с заявленной, как было.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_estimates', function (Blueprint $t) {
            $t->boolean('show_declared')->default(true)->after('base_price');
        });
    }

    public function down(): void
    {
        Schema::table('offer_estimates', fn (Blueprint $t) => $t->dropColumn('show_declared'));
    }
};
