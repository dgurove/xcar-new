<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Вендор парковки (24.09.2026, решение владельца: «с парковки из вендоров убирай не парковочных»): в разделе
 * «Вендоры» парковки только те, с кем она работает — были ТС или свой прайс. Дальше признак ставит ТС вендора,
 * его строка прайса или «Новый вендор» на парковке; вендоры одной продажи (из писем с предложениями) — только в CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->boolean('on_park')->default(false));
        DB::statement('update vendors set on_park = true where exists (select 1 from park_vehicles v where v.vendor_id = vendors.id)
            or exists (select 1 from park_tariffs t where t.vendor_id = vendors.id)');
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('on_park'));
    }
};
