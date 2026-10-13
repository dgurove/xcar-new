<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Сразу в продажу» (13.10.2026, начальник): ТС вендора, что почта завела заявкой на приём, сама уходит в продажу, когда
 * документы прочитаны и фото доехали (`Park\Actions\AutoSale`). Сразу включено у АльфаСтрахования Москвы и СПб: оценочная
 * у них известна, закупочная считается правилом — черновик встаёт в «Без продажной цены».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vendors', 'auto_sale')) {
            Schema::table('vendors', fn (Blueprint $t) => $t->boolean('auto_sale')->default(false));
        }
        DB::table('vendors')->whereRaw("name ilike '%альфа%'")->where('on_park', true)->update(['auto_sale' => true]);
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('auto_sale'));
    }
};
