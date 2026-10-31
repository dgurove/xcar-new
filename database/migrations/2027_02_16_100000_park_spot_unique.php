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
        DB::statement("update park_vehicles v set spot = null where v.state = 'stored' and v.spot is not null and exists (
            select 1 from park_vehicles o where o.yard_id = v.yard_id and o.spot = v.spot and o.state = 'stored' and o.id < v.id)");
        DB::statement("create unique index park_vehicles_yard_spot_unique on park_vehicles (yard_id, spot) where state = 'stored' and spot is not null");
    }

    public function down(): void
    {
        DB::statement('drop index if exists park_vehicles_yard_spot_unique');
    }
};
