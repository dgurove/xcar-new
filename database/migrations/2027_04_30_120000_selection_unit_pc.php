<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Подбор в счетах — снова «шт» (24.11.2026, владелец: «обычно шт пишут даже у услуг»; «усл» — условная единица ОКЕИ 876). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('billing_charges')->where('kind', 'selection')->where('unit', 'svc')->update(['unit' => 'pc']);
    }

    public function down(): void {}
};
