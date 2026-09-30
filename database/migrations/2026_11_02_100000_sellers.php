<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Два продавца (решение владельца 30.09.2026): парковка — ИП Кузнецов, сделки и гараж — ООО «ПРАЙМ».
 * Строк «мы» теперь две, у каждой `seller`; счёт помнит продавца, номера — свои у каждого в году.
 * Счета по ТС парковки — ИП (выставленных от ПРАЙМ нет). НДС парковки — ставкой продавца, галка вендора
 * «Хранение с НДС» больше не нужна.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_parties', fn (Blueprint $t) => $t->string('seller', 8)->nullable()->unique());
        DB::statement('drop index if exists billing_parties_self');
        DB::table('billing_parties')->where('is_self', true)->update(['seller' => 'prime']);
        $c = config('xcar.park_company', []);
        DB::table('billing_parties')->insert([
            'kind' => 'ip', 'name' => $c['name'] ?? 'ИП Кузнецов Андрей Викторович', 'is_self' => true, 'seller' => 'park',
            'inn' => $c['inn'] ?? null, 'ogrn' => $c['ogrn'] ?? null, 'legal_address' => $c['address'] ?? null, 'director' => $c['director'] ?? null,
            'bank_name' => $c['bank'] ?? null, 'bik' => $c['bik'] ?? null, 'account' => $c['account'] ?? null, 'corr_account' => $c['corr_account'] ?? null,
            'phone' => $c['phone'] ?? null, 'email' => $c['email'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->string('seller', 8)->default('prime');
            $t->dropUnique(['year', 'number']);
            $t->unique(['seller', 'year', 'number']);
        });
        DB::table('billing_invoices')->whereNotNull('vehicle_id')->update(['seller' => 'park']);

        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('vat_included'));
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->boolean('vat_included')->default(false));
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->dropUnique(['seller', 'year', 'number']);
            $t->unique(['year', 'number']);
            $t->dropColumn('seller');
        });
        DB::table('billing_parties')->where('seller', 'park')->delete();
        Schema::table('billing_parties', fn (Blueprint $t) => $t->dropColumn('seller'));
        DB::statement('create unique index billing_parties_self on billing_parties (is_self) where is_self');
    }
};
