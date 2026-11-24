<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Оплата за подбор» — часть «Менеджер должен нам» (24.11.2026, владелец: «объединим»): ответственные за прежнюю вкладку —
 * ответственными за общую, без повторов; прежняя запись снимается.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("insert into offer_tab_owners (preset, user_id, created_at)
            select 'paying', s.user_id, now() from offer_tab_owners s
            where s.preset = 'selection' and not exists (select 1 from offer_tab_owners p where p.preset = 'paying' and p.user_id = s.user_id)");
        DB::table('offer_tab_owners')->where('preset', 'selection')->delete();
    }

    public function down(): void {}
};
