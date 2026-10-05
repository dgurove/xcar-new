<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Три схемы оплаты сделки (05.10.2026): «ПРАЙМ по счёту» (бывшее «нам»), «Страхователю по ДКП», «Страховой напрямую».
 * Гаражной «платит менеджер» — наша доля (`share`), по ссылке. У ДКП — кто платит по счёту ПРАЙМ: покупатель или сам
 * менеджер (`payer`). Совкомбанк — формат «комиссионная»: по умолчанию ПРАЙМ по счёту.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('deals')->where('scheme', 'ours')->update(['scheme' => 'prime']);
        Schema::table('deals', function (Blueprint $table) {
            $table->string('scheme', 16)->default('prime')->change();
            $table->unsignedBigInteger('share')->nullable()->after('owner_price');
        });
        Schema::table('deal_contracts', function (Blueprint $table) {
            $table->string('payer', 8)->default('buyer')->after('buyer_user_id');
        });
        DB::table('vendors')->whereRaw("name ilike '%совком%'")->update(['deal_format' => 'commission']);
    }

    public function down(): void
    {
        Schema::table('deal_contracts', fn (Blueprint $t) => $t->dropColumn('payer'));
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('share'));
        DB::table('deals')->where('scheme', 'prime')->update(['scheme' => 'ours']);
    }
};
