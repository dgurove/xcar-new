<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Счёт-реестр вендору за месяц (08.10.2026, решение владельца): один счёт на плательщика при закрытии месяца, машины —
 * строками хранения со своим `vehicle_id`, у самого счёта ТС нет. `month` — первое число месяца, за который он.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->date('month')->nullable()->after('kind'));
    }

    public function down(): void
    {
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->dropColumn('month'));
    }
};
