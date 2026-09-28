<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Контрпредложение поставщика: строки закупки с его итоговой ценой уходят в предложения.
 * Закупка знает своего вендора (он становится вендором предложений), ТС — своё предложение.
 * Вендора «Каркаде» не было — заводится здесь и привязывается к его закупкам.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', fn (Blueprint $t) => $t->foreignId('vendor_id')->nullable()->after('supplier')->constrained()->nullOnDelete());
        Schema::table('purchase_cars', function (Blueprint $t) {
            $t->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('announced_at')->nullable();
        });

        $vendor = DB::table('vendors')->whereRaw("name ilike '%каркаде%' or name ilike '%carcade%'")->value('id')
            ?? DB::table('vendors')->insertGetId(['name' => 'Каркаде', 'kind' => 'leasing', 'legal_name' => 'ООО «Каркаде»', 'offers_include_vat' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchases')->whereNull('vendor_id')->whereRaw("supplier ilike '%carcade%' or supplier ilike '%каркаде%' or title ilike '%carcade%'")->update(['vendor_id' => $vendor]);
    }

    public function down(): void
    {
        Schema::table('purchase_cars', function (Blueprint $t) {
            $t->dropConstrainedForeignId('offer_id');
            $t->dropColumn('announced_at');
        });
        Schema::table('purchases', fn (Blueprint $t) => $t->dropConstrainedForeignId('vendor_id'));
    }
};
