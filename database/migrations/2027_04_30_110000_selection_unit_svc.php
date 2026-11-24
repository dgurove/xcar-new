<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Строки подбора в счетах — услуга «усл», а не «шт» (24.11.2026, владелец). Счёт рисуется из строк при каждом открытии. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('billing_charges')->where('kind', 'selection')->where('unit', 'pc')->update(['unit' => 'svc']);
    }

    public function down(): void {}
};
