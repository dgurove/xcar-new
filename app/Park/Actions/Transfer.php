<?php

namespace App\Park\Actions;

use App\Park\City;
use App\Park\CityGuess;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Nav;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Перевод стоящей ТС в другой город (Питер, 09.10.2026: «передано на базу в Москву» — единичный случай): ТС остаётся
 * той же записью, со своими письмами и файлами, дата приёма не сдвигается. Парковка там известна — ТС на ней; нет —
 * стоит в городе без парковки, и её управляющий видит шаг «Нужно указать парковку» (`SetYard` правит тогда событие
 * перевода, а не приёма). Город ТС меняется: управляющий прежнего города её больше не видит.
 * Заявку, ушедшую не в тот город, не переводят, а решают городом — `SetCity`.
 */
final class Transfer
{
    public function __invoke(Vehicle $vehicle, User $by, ?Yard $to, ?int $city, CarbonInterface $day, ?string $note = null): Vehicle
    {
        Nav::forgetStaffCounts();

        return DB::transaction(function () use ($vehicle, $by, $to, $city, $day, $note) {
            $vehicle = Vehicle::withoutGlobalScope(City::SCOPE)->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($vehicle->state !== VehicleState::Stored) {
                throw ValidationException::withMessages(['state' => 'Перевести можно только ТС на парковке']);
            }
            $city = $to?->settlement_id ?? $city;
            if (! $city || (int) $city === (int) $vehicle->city_id) {
                throw ValidationException::withMessages(['to' => 'Выберите другой город']);
            }
            if ($vehicle->accepted_at && $day->copy()->startOfDay()->lt($vehicle->accepted_at->copy()->startOfDay())) {
                throw ValidationException::withMessages(['day' => 'Перевод раньше приёма']);
            }
            $from = $vehicle->yard?->name;
            $fromCity = $vehicle->city_id;
            $spot = $to ? Vehicle::takeSpot($to, null, $vehicle->id) : null;
            // Без парковки город — решение человека (`CityGuess::MANUAL`), с парковкой — её.
            $vehicle->forceFill(['yard_id' => $to?->id, 'spot' => $spot, 'city_id' => $city, 'city_source' => $to ? null : CityGuess::MANUAL])->save();
            $vehicle->log(EventType::Moved, $by, array_filter([
                'from' => $from, 'to' => $to?->name ?? City::name($city).', парковка не указана', 'yard_id' => $to?->id,
                'day' => $day->toDateString(), 'transfer' => true, 'from_city' => $fromCity, 'note' => $note,
            ], fn ($v) => $v !== null && $v !== ''));

            return $vehicle;
        });
    }
}
