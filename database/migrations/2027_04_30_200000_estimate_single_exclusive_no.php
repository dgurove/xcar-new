<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Просьба оценить одному (никто больше не думал, не корректировка) — вопрос «Эксклюзив: Нет / 3 / 5 / 7» был, «Нет» хранился
 * пустым и читался «не решали» (24.11.2026: пачка «Оцененных» не выпустила 9 машин, `Exclusive::undecided`). Такие — 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update(<<<'SQL'
            update offer_estimates e set exclusive_days = 0
            where e.exclusive_days is null and e.base_price is null
              and not exists (select 1 from offer_estimates s where s.offer_id = e.offer_id and s.id <> e.id and s.base_price is null
                  and s.created_at = e.created_at)
              and not exists (select 1 from offer_estimates s where s.offer_id = e.offer_id and s.id <> e.id and s.base_price is null
                  and s.created_at < e.created_at and coalesce(s.estimated_at, s.declined_at, s.withdrawn_at, 'infinity') > e.created_at)
            SQL);
    }

    public function down(): void {}
};
