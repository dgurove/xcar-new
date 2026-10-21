<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportRequested;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
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
 * машины один — повторный вызов отдаёт его. Отменили, а вывоз снова нужен (18.10.2026: сменить исполнителя отменой
 * — по одной машине копились задачи) — снова открывается прежняя задача, с её историей, а не новая строка. Владелец, телефон и адрес задача не копирует: читает у машины и предложения
 * (их дописывает письмо и правят в CRM). Без исполнителя задача ждёт админа («Без исполнителя», уведомление админам).
 * `quiet` — везёт сам менеджер сделки или гаража (это не логистика: ни «Вывоз: …» админам, ни строки «задача в логистике»
 * в истории; вывоз в истории пишет назначение) или просьбу логистам пишет вызывающий (`RequestPickup`).
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

        $t = DB::transaction(function () use ($offer, $by, $to, $quiet) {
            $fields = ['state' => TransportState::New, 'destination' => $to ?? ($offer->garageCar()->exists() ? Point::Keeper : null)];
            // Машина стоит у менеджера без его сделки (`held_by`, 21.10.2026) — забирать у него: он и контакт на месте.
            $held = $offer->car_place === CarPlace::Keeper ? $offer->heldBy : null;
            $fields += ['from_contact' => $held?->name, 'from_phone' => $held?->phone];
            $t = Transport::where('car_id', $offer->car_id)->where('origin', 'sale')->where('kind', TransportKind::Pickup)
                ->where('state', TransportState::Cancelled)->whereNull('picked_at')->latest('id')->first();
            if ($t) {
                $t->update($fields + ['channel' => null, 'executor_id' => null, 'logist_id' => null, 'cost' => null, 'distance_km' => null,
                    'assigned_at' => null, 'cancelled_at' => null, 'cancel_reason' => null, 'docs_by_cdek' => false,
                    'docs_track' => null, 'docs_sent_at' => null, 'docs_sent_by' => null, 'docs_received_at' => null]);
                TransportEvent::record($t, 'reopened', $by);
            } else {
                $t = Transport::create($fields + ['car_id' => $offer->car_id, 'origin' => 'sale', 'kind' => TransportKind::Pickup, 'created_by' => $by?->id]);
                TransportEvent::record($t, 'opened', $by);
            }
            if ($offer->car_place === null) {
                ($this->place)($offer, CarPlace::Owner, $by);
            }
            if (! $quiet) {
                $offer->log(OfferEventType::Note, $by, ['text' => 'Вывоз: задача в логистике']);
            }

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
