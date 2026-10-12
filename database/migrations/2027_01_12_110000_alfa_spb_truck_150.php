<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Альфа СПб: грузовые — 150 ₽/сут (12.10.2026, владельцу сказали: «в СПб Альфа 120 ₽ легковые и 150 ₽ грузовые»; было 250 из
 * копии ВСК). Легковые 120 — уже так. Цена правится на месте: по договору так было всегда, невыставленные дни — по ней.
 */
return new class extends Migration
{
    public function up(): void
    {
        $vendor = DB::table('vendors')->where('name', 'АльфаСтрахование СПб')->value('id');
        if ($vendor) {
            DB::table('park_tariffs')->where('vendor_id', $vendor)->where('service', 'storage')->where('category', 'truck')->whereNull('valid_to')->update(['price' => 150]);
        }
    }

    public function down(): void {}
};
