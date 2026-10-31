<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Одно место — одна ТС на парковке (31.10.2026, схема парковки): раньше это проверял только `Vehicle::takeSpot`, и две
 * перестановки разом ставили две машины на одно место. Повторы, если есть, остаются у первой принятой, у прочих место
 * снимается — они попадают в «без места» на схеме.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("update park_vehicles set spot = null where id in (
            select id from (select id, row_number() over (partition by yard_id, spot order by accepted_at nulls last, id) as n
                from park_vehicles where state = 'stored' and spot is not null) t where t.n > 1)");
        DB::statement("create unique index park_vehicles_yard_spot_unique on park_vehicles (yard_id, spot) where state = 'stored' and spot is not null");
    }

    public function down(): void
    {
        DB::statement('drop index if exists park_vehicles_yard_spot_unique');
    }
};
