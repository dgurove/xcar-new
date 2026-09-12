<?php

namespace App\Cars\Vin;

use App\Offers\Offer;
use App\Park\Vehicle;
use App\Purchases\Car;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Запоминает машину для декодера: VIN и то, что о ней записано руками.
 * Без марки или с невалидным VIN запись источника стирается — номер могли
 * исправить, и старый факт больше ничего не подтверждает.
 */
final class RememberVin
{
    public function __invoke(Offer|Car|Vehicle $car): void
    {
        $type = match (true) {
            $car instanceof Offer => 'offer',
            $car instanceof Car => 'purchase_car',
            default => 'park_vehicle',
        };
        $vin = VinDecoder::normalize((string) $car->vin);

        if (! $car->brand_id || ! VinDecoder::looksValid($vin)) {
            VinFact::where('source_type', $type)->where('source_id', $car->getKey())->delete();

            return;
        }

        $facts = ['vin' => $vin, 'prefix' => substr($vin, 0, 11)];
        foreach (VinFact::FIELDS as $field) {
            $value = $car->getAttribute($field);
            $facts[$field] = $value instanceof BackedEnum ? $value->value : $value;
        }
        VinFact::updateOrCreate(['source_type' => $type, 'source_id' => $car->getKey()], $facts);
    }

    /** Все машины базы — один раз после появления памяти. */
    public static function learnAll(): int
    {
        $n = 0;
        foreach ([Offer::class, Car::class, Vehicle::class] as $class) {
            $class::query()->whereNotNull('vin')->whereNotNull('brand_id')->each(function (Model $car) use (&$n) {
                (new self)($car);
                $n++;
            });
        }

        return $n;
    }
}
