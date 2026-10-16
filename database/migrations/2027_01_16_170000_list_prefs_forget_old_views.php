<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Вид списка на сайте снова помнится (16.10.2026, `ListPrefs::sync`). В памяти людей лежат виды тех времён, когда он уже
 * помнился, — из-за них 11.10 у людей застревали строки: стираем, помнится только выбранное после этого.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('users')->whereNotNull('list_prefs')->get(['id', 'list_prefs']) as $user) {
            $prefs = json_decode($user->list_prefs, true);
            if (! is_array($prefs)) {
                continue;
            }
            $clean = array_map(fn ($list) => is_array($list) ? array_diff_key($list, ['vid' => true]) : $list, $prefs);
            if ($clean !== $prefs) {
                DB::table('users')->where('id', $user->id)->update(['list_prefs' => json_encode($clean)]);
            }
        }
    }

    public function down(): void {}
};
