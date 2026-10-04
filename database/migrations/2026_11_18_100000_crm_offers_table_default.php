<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Предложения CRM открываются таблицей (04.10.2026, владелец): запомненные «строками» и «плитками» забываются один раз,
 * дальше выбор вида помнится, как раньше, вместе с таблицей.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNotNull('list_prefs')->orderBy('id')->each(function ($u) {
            $prefs = json_decode($u->list_prefs, true);
            if (! isset($prefs['crm-offers']['vid'])) {
                return;
            }
            unset($prefs['crm-offers']['vid']);
            DB::table('users')->where('id', $u->id)->update(['list_prefs' => json_encode($prefs, JSON_UNESCAPED_UNICODE)]);
        });
    }

    public function down(): void {}
};
