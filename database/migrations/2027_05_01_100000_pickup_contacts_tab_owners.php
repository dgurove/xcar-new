<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Показать контакты» — первая вкладка «Вывоза» (25.11.2026, владелец: «ответственные Катя, Лиза, Алина»): те же, что у «Назначить
 * исполнителя».
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("insert into offer_tab_owners (preset, user_id, created_at)
            select 'pickup_contacts', s.user_id, now() from offer_tab_owners s
            where s.preset = 'pickup_assign' and not exists (select 1 from offer_tab_owners p where p.preset = 'pickup_contacts' and p.user_id = s.user_id)");
    }

    public function down(): void
    {
        DB::table('offer_tab_owners')->where('preset', 'pickup_contacts')->delete();
    }
};
