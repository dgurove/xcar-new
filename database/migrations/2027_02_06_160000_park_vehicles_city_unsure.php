<?php

use App\Park\City;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Город ТС без парковки — заново по `City::vendorCity` (27.10.2026: заявка ВСК из Питера встала в Москву, и питерский
 * управляющий её не видел): у вендора, чьи ТС стояли в разных городах, город пуст — ТС видна всем городам, пока её не
 * примут. Переведённые руками (событие перевода) не трогаются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Vehicle::withoutGlobalScope(City::SCOPE)->whereNull('yard_id')->whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled])
            ->whereNotExists(fn ($q) => $q->from('park_vehicle_events')->whereColumn('park_vehicle_events.vehicle_id', 'park_vehicles.id')
                ->whereIn('type', ['moved', 'note'])->where(fn ($w) => $w->whereRaw("payload->>'transfer' = 'true'")->orWhereRaw("payload->>'text' like 'Заявка %переведена%'")))
            ->get(['id', 'vendor_id', 'city_id'])
            ->each(fn ($v) => DB::table('park_vehicles')->where('id', $v->id)->update(['city_id' => City::vendorCity($v->vendor_id)]));
    }

    public function down(): void {}
};
