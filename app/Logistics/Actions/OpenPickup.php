<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportRequested;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Logistics\TransportKind;
use App\Logistics\TransportState;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Park\VehicleState;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Завести вывоз машины (17.10.2026) — одна дверь: письмо вендора с `pickup_on_arrival` (Совкомбанк: обязаны вывезти
 * каждую), «Вывезти» в CRM, гараж (`Garage\Actions\EnsurePickup`), «Принять» с «Забирает менеджер». Открытый вывоз у
 * машины один — повторный вызов отдаёт его. Владелец, телефон и адрес задача не копирует: читает у машины и предложения
 * (их дописывает письмо и правят в CRM). Без исполнителя задача ждёт админа («Без исполнителя», уведомление админам).
 */
final class OpenPickup
{
    public function __construct(private SetCarPlace $place) {}

    public function __invoke(Offer $offer, ?User $by = null, ?Point $to = null, bool $quiet = false): Transport
    {
        if ($open = Transport::openFor($offer)) {
            return $open;
        }
        if ($reason = self::blocker($offer)) {
            throw ValidationException::withMessages(['pickup' => $reason]);
        }

        $t = DB::transaction(function () use ($offer, $by, $to) {
            $t = Transport::create([
                'car_id' => $offer->car_id, 'origin' => 'sale', 'kind' => TransportKind::Pickup, 'state' => TransportState::New,
                'destination' => $to ?? ($offer->garageCar()->exists() ? Point::Keeper : null),
                'created_by' => $by?->id,
            ]);
            if ($offer->car_place === null) {
                ($this->place)($offer, CarPlace::Owner, $by);
            }
            $offer->log(OfferEventType::Note, $by, ['text' => 'Вывоз: задача в логистике']);

            return $t;
        });
        if (! $quiet) {
            TransportRequested::dispatch($t);
        }

        return $t;
    }

    /** Почему вывоз завести нельзя: продажа закрыта, ТС уже не у владельца (у нас, у менеджера, на парковке). null — можно. */
    public static function blocker(Offer $offer): ?string
    {
        return match (true) {
            in_array($offer->state, [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived], true) => 'Предложение '.mb_strtolower($offer->state->label()).', вывоз не нужен',
            $offer->pickedUp() || $offer->parkVehicle?->state === VehicleState::Stored => 'ТС уже не у владельца',
            default => null,
        };
    }
}
