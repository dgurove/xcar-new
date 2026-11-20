<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Метка «С НДС» сама за ставкой поставщика и НДС грузовых по умолчанию у вендора (20.11.2026, владелец; Корабельников: «почти все
 * грузовики Альфы с НДС через Р.О.С.» — на проде из 76 сделок 20 через Р.О.С., и у 10 из них НДС не стоял: Альфа о НДС до
 * продажи не пишет). Альфе — 22 % грузовым; сделкам через Р.О.С. без ставки — 22 %; метка — всем с НДС.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->unsignedSmallInteger('supplier_vat_heavy')->nullable());
        if (! DB::table('tags')->where('name', 'С НДС')->exists()) {
            DB::table('tags')->insert(['name' => 'С НДС', 'color' => 'blue', 'sort' => (int) DB::table('tags')->max('sort') + 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('vendors')->where('name', 'ilike', 'АльфаСтрахование%')->update(['supplier_vat_heavy' => 22]);
        DB::table('offers')->whereNull('supplier_vat')->whereIn('id', DB::table('deals')->where('scheme', 'ros')->whereNull('cancelled_at')->select('offer_id'))
            ->update(['supplier_vat' => 22, 'supplier_vat_source' => 'vendor', 'prices_include_vat' => true]);
        DB::statement("update offers set tags = coalesce(tags, '[]'::jsonb) || '[\"С НДС\"]'::jsonb where supplier_vat > 0 and not coalesce(tags, '[]'::jsonb) @> '[\"С НДС\"]'::jsonb");
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('supplier_vat_heavy'));
    }
};
