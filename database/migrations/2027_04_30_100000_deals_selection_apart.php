<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Подбор отдельной ссылкой — выбором у сделки (24.11.2026, владелец: «если через ПРАЙМ, должно быть в стоимость ТС в счёте включено;
 * можно добавить функцию, чтобы отдельно ссылкой, но по умолчанию счёт единый»). С 20.11 подбор отделялся сам, когда НДС
 * поставщика не 5 % (у Совкомбанка — всегда): сделки, где счёт подбора ПРАЙМ уже стоит, остаются раздельными, чтобы не выставить
 * подбор дважды; остальные — единым счётом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->boolean('selection_apart')->default(false);
        });
        DB::statement("update deals set selection_apart = true where scheme in ('prime', 'ros') and exists (
            select 1 from billing_invoices i where i.deal_id = deals.id and i.direction = 'issued' and i.kind = 'selection' and i.state <> 'void')");
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('selection_apart'));
    }
};
