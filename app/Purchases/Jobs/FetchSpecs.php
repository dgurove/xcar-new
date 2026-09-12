<?php

namespace App\Purchases\Jobs;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Fuel;
use App\Cars\Transmission;
use App\Cars\Vin\RememberVin;
use App\Purchases\Car;
use App\Purchases\Carcade;
use App\Purchases\Gone;
use App\Purchases\ImportState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/** Характеристики с карточки поставщика. Цены оттуда не берём — они лизинговые. */
final class FetchSpecs implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $carId)
    {
        $this->onConnection('database-long')->onQueue('long');
    }

    public function handle(Carcade $carcade): void
    {
        $car = Car::find($this->carId);
        if (! $car?->site_url) {
            return;
        }
        $car->forceFill(['specs_state' => ImportState::Running])->save();
        try {
            $s = $carcade->specs($car->site_url);
        } catch (Gone $e) {
            $car->forceFill(['specs_state' => ImportState::Gone, 'specs_error' => $e->getMessage(), 'specs_at' => now()])->save();

            return;
        } catch (Throwable $e) {
            $car->forceFill(['specs_state' => ImportState::Failed, 'specs_error' => Str::limit($e->getMessage(), 280), 'specs_at' => now()])->save();

            return;
        }
        if ($s['dl'] && $car->dl && $s['dl'] !== $car->dl) {
            $car->forceFill(['specs_state' => ImportState::Failed, 'specs_error' => "Ссылка ведёт на другую машину (ДЛ {$s['dl']})", 'specs_at' => now()])->save();

            return;
        }
        $set = fn (string $field, $value) => $value !== null && ! $car->isLocked($field) ? $car->{$field} = $value : null;
        $set('vin', $s['vin']);
        $set('year', $s['year']);
        $set('mileage', $s['mileage']);
        $set('engine_power', $s['engine_power']);
        $set('engine_volume', $s['engine_volume']);
        $set('color', $s['color']);
        $set('keys', $s['keys']);
        $set('steering', $s['steering']);
        $set('condition', $s['condition']);
        $set('city', $s['city']);
        $set('address', $s['address'] ?? $car->address);
        if ($s['fssp'] !== null) {
            $car->fssp = $s['fssp'];
        }
        $set('transmission', match (true) {
            $s['transmission'] === null => null,
            str_contains(mb_strtolower($s['transmission']), 'автомат') => Transmission::Automatic,
            str_contains(mb_strtolower($s['transmission']), 'вариат') => Transmission::Cvt,
            str_contains(mb_strtolower($s['transmission']), 'робот') => Transmission::DualClutch,
            str_contains(mb_strtolower($s['transmission']), 'механ') => Transmission::Manual,
            default => null,
        });
        $set('fuel', match (true) {
            $s['fuel'] === null => null,
            str_contains(mb_strtolower($s['fuel']), 'дизел') => Fuel::Diesel,
            str_contains(mb_strtolower($s['fuel']), 'бензин') => Fuel::Petrol,
            str_contains(mb_strtolower($s['fuel']), 'гибрид') => Fuel::Hybrid,
            str_contains(mb_strtolower($s['fuel']), 'электр') => Fuel::Electric,
            str_contains(mb_strtolower($s['fuel']), 'газ') => Fuel::Gas,
            default => null,
        });
        if ($s['brand'] && ! $car->isLocked('brand_id')) {
            $brand = Brand::resolve($s['brand']);
            $car->brand_id = $brand->id;
            $car->brand_raw = $s['brand'];
            if ($s['model']) {
                $car->model_id = CarModel::resolve($brand, $s['model'])->id;
                $car->model_raw = $s['model'];
            }
        }
        $car->forceFill(['specs_state' => ImportState::Done, 'specs_error' => null, 'specs_at' => now()])->save();
        (new RememberVin)($car);

        // Кадры с карточки поставщика — когда облака нет: их там 5–10, для списка хватит.
        if (! $car->cloud_url && $s['pictures'] && $car->photos()->isEmpty()) {
            FetchPhotos::dispatch($car->id, pictures: $s['pictures']);
        }
    }
}
