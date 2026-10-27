<?php

namespace App\Park\Actions;

use App\Park\City;
use App\Park\CityGuess;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * «Это Санкт-Петербург» — человек решает город ТС, которой система не уверена (`CityGuess`, 27.10.2026): одно нажатие
 * вместо перевода. Решение — источник `manual`, его не перетирает ни письмо, ни правка полей, только парковка при приёме.
 * Попадает и в историю отправителя: следующая его заявка встанет в этот город сама. Управляющий одного города решает
 * только за свой; ТС на парковке — город парковки, менять его — «Перевести».
 */
final class SetCity
{
    public function __invoke(Vehicle $vehicle, User $by, int $city): Vehicle
    {
        if (! array_key_exists($city, City::options()) || (($own = City::of($by)) && $own !== $city)) {
            throw ValidationException::withMessages(['city' => 'Этот город выбрать нельзя']);
        }
        if ($vehicle->yard_id) {
            throw ValidationException::withMessages(['city' => 'ТС на парковке — её город по парковке, другой — «Перевести»']);
        }
        $was = $vehicle->city_id;
        $vehicle->forceFill(['city_id' => $city, 'city_source' => CityGuess::MANUAL])->save();
        $vehicle->log(EventType::Note, $by, ['text' => 'Город: '.City::name($city).($was && (int) $was !== $city ? ' (был '.City::name($was).')' : '')]);
        Nav::forgetStaffCounts();

        return $vehicle;
    }
}
