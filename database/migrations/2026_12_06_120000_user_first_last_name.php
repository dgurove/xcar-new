<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Имя и фамилия — двумя полями во всех анкетах и редакторах (владелец 05.10.2026). `name` остаётся «Имя Фамилия» и
 * собирается сам (`User::booted`): по нему ищут, сортируют и рисуют короткое имя. Разбивка — по первому слову; где
 * фамилию написали первой, правится руками.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('first_name', 60)->nullable()->after('name');
            $t->string('last_name', 60)->nullable()->after('first_name');
        });
        foreach (DB::table('users')->get(['id', 'name']) as $u) {
            $parts = preg_split('/\s+/u', trim((string) $u->name), 2) ?: [];
            DB::table('users')->where('id', $u->id)->update(['first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? null]);
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['first_name', 'last_name']));
    }
};
