<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Вендор парковки, который в CRM — другой вендор (`crm_vendor_id`): «АльфаСтрахование СПб» на парковке свой (у Питера
 * опоздавший покупатель платит, в Москве нет), а в продаже это та же «АльфаСтрахование» (владелец 04.10.2026).
 * Её предложения переходят к основной.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->foreignId('crm_vendor_id')->nullable()->constrained('vendors')->nullOnDelete());
        $main = DB::table('vendors')->where('name', 'АльфаСтрахование')->value('id');
        $spb = DB::table('vendors')->where('name', 'АльфаСтрахование СПб')->value('id');
        if ($main && $spb) {
            DB::table('vendors')->where('id', $spb)->update(['crm_vendor_id' => $main]);
            DB::table('offers')->where('vendor_id', $spb)->update(['vendor_id' => $main]);
        }
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->dropConstrainedForeignId('crm_vendor_id'));
    }
};
