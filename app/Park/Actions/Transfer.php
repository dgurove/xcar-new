<?php

namespace App\Park\Actions;

use App\Mail\Message;
use App\Park\City;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Nav;
use App\Users\User;
use App\Vendors\Contact;
use App\Vendors\ContactRole;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Перевод стоящей ТС в другой город (Питер, 09.10.2026: «передано на базу в Москву» — единичный случай): ТС остаётся
 * той же записью, со своими письмами и файлами, дата приёма не сдвигается. Парковка там известна — ТС на ней; нет —
 * стоит в городе без парковки, и её управляющий видит шаг «Нужно указать парковку» (`SetYard` правит тогда событие
 * перевода, а не приёма). Город ТС меняется: управляющий прежнего города её больше не видит. Ещё не принятую — тоже:
 * заявка ушла не в тот город (27.10.2026, ВСК из Питера) — такой перевод запоминает парковку отправителя заявки (`learn`).
 */
final class Transfer
{
    public function __invoke(Vehicle $vehicle, User $by, ?Yard $to, ?int $city, CarbonInterface $day, ?string $note = null): Vehicle
    {
        Nav::forgetStaffCounts();

        return DB::transaction(function () use ($vehicle, $by, $to, $city, $day, $note) {
            $vehicle = Vehicle::withoutGlobalScope(City::SCOPE)->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if (! in_array($vehicle->state, [VehicleState::Stored, VehicleState::Expected], true)) {
                throw ValidationException::withMessages(['state' => 'Перевести можно ТС на парковке или ещё не принятую']);
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
            // Ещё не принята — заявка ушла не в тот город: меняется город (и парковка, если выбрали), перемещения не было.
            if ($vehicle->state === VehicleState::Expected) {
                $vehicle->forceFill(['yard_id' => $to?->id, 'city_id' => $city])->save();
                $vehicle->log(EventType::Note, $by, ['text' => 'Заявка переведена: '.City::name($fromCity).' → '.($to?->name ?? City::name($city))]);
                $this->learn($vehicle, $to ?? self::onlyYard($city));

                return $vehicle;
            }
            $spot = $to ? Vehicle::takeSpot($to, null, $vehicle->id) : null;
            $vehicle->forceFill(['yard_id' => $to?->id, 'spot' => $spot, 'city_id' => $city])->save();
            $vehicle->log(EventType::Moved, $by, array_filter([
                'from' => $from, 'to' => $to?->name ?? City::name($city).', парковка не указана', 'yard_id' => $to?->id,
                'day' => $day->toDateString(), 'transfer' => true, 'from_city' => $fromCity, 'note' => $note,
            ], fn ($v) => $v !== null && $v !== ''));

            return $vehicle;
        });
    }

    /**
     * Перевод ещё не принятой учит, чьи это машины (27.10.2026: заявки питерского эксперта ВСК вставали в Москву). Только
     * она: перевод стоящей — перегон машины («уехала на базу в Москву»), отправитель от этого питерским не стал. Отправителю писем
     * заявок этой ТС у её вендора ставится парковка перевода (`Contact::yardFor`), нет контакта — заводится. Следующая
     * заявка от него встанет сразу туда. Парковка города не известна — учить нечему.
     */
    private function learn(Vehicle $vehicle, ?Yard $yard): void
    {
        if (! $yard || ! $vehicle->vendor_id) {
            return;
        }
        $senders = Message::whereIn('id', $vehicle->requests()->whereNotNull('letter_id')->select('letter_id'))->pluck('from_email')
            ->map(fn ($e) => mb_strtolower(trim((string) $e)))->filter()->unique();
        foreach ($senders as $email) {
            $contact = Contact::whereRaw('lower(email) = ?', [$email])->where('vendor_id', $vehicle->vendor_id)->first()
                ?? new Contact(['vendor_id' => $vehicle->vendor_id, 'email' => $email, 'name' => Str::before($email, '@'), 'role' => ContactRole::Storage]);
            $contact->yard_id = $yard->id;
            $contact->save();
        }
    }

    /** Единственная действующая парковка города — её и запоминаем; их несколько или нет — null. */
    private static function onlyYard(int $city): ?Yard
    {
        $yards = Yard::withoutGlobalScope(City::SCOPE)->where('settlement_id', $city)->where('is_active', true)->limit(2)->get();

        return $yards->count() === 1 ? $yards->first() : null;
    }
}
