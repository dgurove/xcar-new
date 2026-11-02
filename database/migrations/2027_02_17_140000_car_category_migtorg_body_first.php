<?php

use App\Cars\Body;
use App\Cars\Car;
use App\Cars\Category;
use App\Offers\MigtorgFields;
use App\Park\City;
use App\Park\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Тип ТС с Мигторга — точный кузов главнее догадки по названию (ревью 02.11.2026, `MigtorgFields::category`): машины, чей
 * тип поставила прежняя проба (`2027_02_17_110000`, догадка раньше кузова — «Isuzu D-Max» с кузовом «Пикап» стал грузовым),
 * получают тип по новому правилу. Выбранный человеком (не совпадающий с прежним правилом) и ТС парковки не трогаются. Что
 * поменялось — `storage/logs/car-category-migtorg-2.log`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lots = DB::table('migtorg_lots')->whereNotNull('data')->orderBy('id')->get(['claim_ref_key', 'data'])->keyBy('claim_ref_key');
        if ($lots->isEmpty()) {
            return;
        }
        $carcassMap = (new ReflectionClassConstant(MigtorgFields::class, 'CARCASS_CATEGORY'))->getValue();
        $typeMap = (new ReflectionClassConstant(MigtorgFields::class, 'TYPE_CATEGORY'))->getValue();
        // Прежнее правило: кузов-прицеп и тягач, догадка по названию, кузов, тип марки.
        $old = function (array $data) use ($carcassMap, $typeMap): ?string {
            $lot = $data['lot'] ?? [];
            $carcass = mb_strtolower(trim((string) ($lot['carcass']['title'] ?? '')));
            $body = Body::tryFrom((string) (MigtorgFields::of($data)['body']['value'] ?? ''));
            $title = trim(trim((string) ($lot['brand']['title'] ?? '')).' '.($lot['model']['title'] ?? ''));

            return ($carcassMap[$carcass] ?? Category::guess($title) ?? ($carcass !== 'универсал' ? $body?->category() : null) ?? $typeMap[(int) ($lot['brand']['car_type_id'] ?? 0)] ?? null)?->value;
        };
        $parked = Vehicle::withoutGlobalScope(City::SCOPE)->pluck('car_id')->flip();
        $log = [];
        Car::whereIn('claim_ref_key', $lots->keys())->with(['brand', 'model'])->each(function (Car $car) use ($lots, $parked, $old, &$log) {
            $data = json_decode($lots[$car->claim_ref_key]->data, true);
            $new = MigtorgFields::of($data)['category']['value'] ?? null;
            $was = $old($data);
            if (! $new || $new === $was || isset($parked[$car->id]) || $car->category?->value !== $was) {
                return;
            }
            DB::table('cars')->where('id', $car->id)->update(['category' => $new]);
            $log[] = "{$car->claim_ref} {$car->title()}: ".Category::from($was)->label().' → '.Category::from($new)->label();
        });
        File::put(storage_path('logs/car-category-migtorg-2.log'), implode("\n", $log)."\n");
    }

    public function down(): void {}
};
