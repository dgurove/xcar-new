<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VIN менеджерам виден по умолчанию, скрыть — глазиком у поля (владелец 04.10.2026: «менеджеры жалуются, что VIN не
 * видят»). Руками VIN до сих пор только открывали, никто не прятал — открываем у всех.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', fn ($t) => $t->boolean('show_vin')->default(true)->change());
        DB::table('offers')->where('show_vin', false)->update(['show_vin' => true]);
    }

    public function down(): void
    {
        Schema::table('offers', fn ($t) => $t->boolean('show_vin')->default(false)->change());
    }
};
