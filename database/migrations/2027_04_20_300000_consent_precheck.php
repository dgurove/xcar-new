<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Доп. запрос до «Беру в работу» (20.11.2026, владелец: Роман Корабельников по всем грузовым Альфы требует запросить ПТС и осмотр
 * до вопроса, берёт ли он машину; остальным не нужно): признак менеджера, Роману — сразу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('consent_precheck')->default(false));
        DB::table('users')->where('name', 'ilike', '%Корабельников%')->whereJsonContains('roles', 'manager')->update(['consent_precheck' => true]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('consent_precheck'));
    }
};
