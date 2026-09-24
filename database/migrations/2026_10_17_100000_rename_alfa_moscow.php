<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «АльфаСтрахование Москва» — просто «АльфаСтрахование» (решение владельца 24.09.2026): основная Альфа — московская,
 * питерская остаётся «АльфаСтрахование СПб». Контрагент для счетов переименовывается, только если назван так же.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('АльфаСтрахование Москва', 'АльфаСтрахование');
    }

    public function down(): void
    {
        $this->rename('АльфаСтрахование', 'АльфаСтрахование Москва');
    }

    private function rename(string $from, string $to): void
    {
        $vendor = DB::table('vendors')->where('name', $from)->first();
        if (! $vendor || DB::table('vendors')->where('name', $to)->exists()) {
            return;
        }
        DB::table('vendors')->where('id', $vendor->id)->update(['name' => $to, 'updated_at' => now()]);
        if ($vendor->party_id) {
            DB::table('billing_parties')->where('id', $vendor->party_id)->where('name', $from)->update(['name' => $to]);
        }
    }
};
