<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Адрес осмотра у предложений из закупок (Каркаде) — скрыт, как у любого предложения (07.10.2026, владелец: «по
 * умолчанию должен быть скрыт, у тачек carcade почему-то открыт»). Открыла его разом миграция purchase_offer_extras,
 * руками его там никто не ставил.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('offers')
            ->whereIn('id', DB::table('purchase_cars')->whereNotNull('offer_id')->select('offer_id'))
            ->where('show_address', true)
            ->update(['show_address' => false]);
    }

    public function down(): void {}
};
