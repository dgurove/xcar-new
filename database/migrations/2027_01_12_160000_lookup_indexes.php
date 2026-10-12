<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Индексы под поиски, что шли полным проходом (12.10.2026, `pg_stat_user_tables`):
 * - марки: `slug like 'x-%'` (`Names::bySlug` — каждое слово письма) и `lower(name)` (`VinAutofill`) — 8 млн проходов;
 * - населённые пункты по региону (`settlements.region_id`) — 155 тыс. строк на каждый поиск города с регионом.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists brands_slug_pattern on brands (slug varchar_pattern_ops)');
        DB::statement('create index if not exists brands_lower_name on brands (lower(name))');
        DB::statement('create index if not exists brands_lower_name_ru on brands (lower(name_ru))');
        DB::statement('create index if not exists settlements_region_id_fk_index on settlements (region_id)');
    }

    public function down(): void
    {
        foreach (['brands_slug_pattern', 'brands_lower_name', 'brands_lower_name_ru', 'settlements_region_id_fk_index'] as $index) {
            DB::statement("drop index if exists {$index}");
        }
    }
};
