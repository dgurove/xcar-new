<?php

use App\Mail\Candidate;
use App\Mail\Scope;
use App\Park\City;
use App\Park\CityGuess;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Город с источником (27.10.2026, `Park\CityGuess`): у ТС и цепочек «Из писем» парковки — `city_source` (`yard`,
 * `manual`, `letter`, `contact`, `sender`, `vendor`), у цепочки — и свой `city_id`. Прошлое: с парковкой — `yard`; без
 * неё, но переведённые или решённые человеком (заявка 144 ВСК) — `manual`; остальные живые и цепочки — заново дверью.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->string('city_source', 16)->nullable());
        Schema::table('mail_candidates', function (Blueprint $t) {
            $t->foreignId('city_id')->nullable()->index();
            $t->string('city_source', 16)->nullable();
        });
        DB::table('park_vehicles')->whereNotNull('yard_id')->update(['city_source' => CityGuess::YARD]);
        DB::table('park_vehicles')->whereNull('yard_id')->whereNotNull('city_id')
            ->whereExists(fn ($q) => $q->from('park_vehicle_events')->whereColumn('park_vehicle_events.vehicle_id', 'park_vehicles.id')
                ->where(fn ($w) => $w->where(fn ($m) => $m->where('type', 'moved')->whereRaw("payload->>'transfer' = 'true'"))
                    ->orWhere(fn ($n) => $n->where('type', 'note')->whereRaw("payload->>'text' like 'Заявка %переведена%'"))))
            ->update(['city_source' => CityGuess::MANUAL]);
        DB::table('park_vehicles')->whereNull('city_source')->whereIn('state', [VehicleState::Released->value, VehicleState::Cancelled->value])->update(['city_source' => 'vendor']);
        City::unscoped(function () {
            Vehicle::withoutGlobalScope(City::SCOPE)->whereNull('city_source')->get()->each->recity();
            Candidate::withoutGlobalScopes()->where('scope', Scope::Park)->whereNull('vehicle_id')->with('messages')->get()
                ->each(function (Candidate $c) {
                    [$city, $source] = CityGuess::letters($c->messages, $c->vendor_id);
                    DB::table('mail_candidates')->where('id', $c->id)->update(['city_id' => $city, 'city_source' => $source]);
                });
        });
    }

    public function down(): void
    {
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->dropColumn('city_source'));
        Schema::table('mail_candidates', fn (Blueprint $t) => $t->dropColumn(['city_id', 'city_source']));
    }
};
