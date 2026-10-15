<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Закупочная менеджера закреплена (15.10.2026, владелец: «страховая скинула — наша заслуга, менеджер не видит»).
 * `deals.cost` — цена, которую менеджер видел, когда подтверждал, после сделки не меняется ничем; новая закупочная от
 * поставщика — `deals.supplier_cost` (только админу, пусто — равна `cost`). `bids.cost` — закупочная в момент
 * подтверждения: её и закрепляет принятие. `offers.shown_floor` — закупочная, какой она была, когда предложение вышло в
 * продажу (от неё заявленная): правят закупочную у открытого — подтверждения берут прежнюю. Переноса нет: у живых сделок
 * нынешняя `cost` и есть закреплённая.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->bigInteger('supplier_cost')->nullable()->after('cost'));
        Schema::table('bids', fn (Blueprint $t) => $t->bigInteger('cost')->nullable());
        Schema::table('offers', fn (Blueprint $t) => $t->bigInteger('shown_floor')->nullable());
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('supplier_cost'));
        Schema::table('bids', fn (Blueprint $t) => $t->dropColumn('cost'));
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('shown_floor'));
    }
};
