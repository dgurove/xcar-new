<?php

use App\Cars\Car;
use App\Cars\Category;
use App\Offers\MigtorgFields;
use App\Park\City;
use App\Park\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Тип ТС из лота Мигторга (02.11.2026, Z691/046/13687/26 «Граз» с кузовом «Прицеп» стоял легковым): машинам, у которых
 * тип — только догадка (`Car::categoryGuessed`), — тип лота (`MigtorgFields`: кузов и тип марки). ТС парковки не трогаем —
 * там тип ставка хранения. Что поменялось — `storage/logs/car-category-migtorg.log`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lots = DB::table('migtorg_lots')->whereNotNull('data')->orderBy('id')->get(['claim_ref_key', 'data'])->keyBy('claim_ref_key');
        if ($lots->isEmpty()) {
            return;
        }
        $parked = Vehicle::withoutGlobalScope(City::SCOPE)->pluck('car_id')->flip();
        $log = [];
        Car::whereIn('claim_ref_key', $lots->keys())->with(['brand', 'model'])->each(function (Car $car) use ($lots, $parked, &$log) {
            $value = MigtorgFields::of(json_decode($lots[$car->claim_ref_key]->data, true))['category']['value'] ?? null;
            if (! $value || isset($parked[$car->id]) || $car->category?->value === $value || ! $car->categoryGuessed()) {
                return;
            }
            DB::table('cars')->where('id', $car->id)->update(['category' => $value]);
            $log[] = "{$car->claim_ref} {$car->title()}: ".($car->category?->label() ?? '—').' → '.Category::from($value)->label();
        });
        File::put(storage_path('logs/car-category-migtorg.log'), implode("\n", $log)."\n");
    }

    public function down(): void {}
};
