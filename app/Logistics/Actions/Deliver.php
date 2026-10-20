<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportMoved;
use App\Logistics\Point;
use App\Logistics\Route;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Park\Actions\Move;
use App\Park\Actions\Transfer;
use App\Park\EventType;
use App\Park\VehicleState;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Привёз»: машина в точке. У продажи место встаёт по точке — у менеджера (`CarPlace::Keeper`) или у нас (`WithUs`);
 * гаражная машина начинает подготовку, шаг получения сделки проходит сам (`Offers\Listeners\SyncHandover`). ТС парковки
 * ставим на парковку назначения (`toYard`).
 */
final class Deliver
{
    public function __construct(private SetCarPlace $place, private Transfer $transfer, private Move $move) {}

    public function __invoke(Transport $t, User $by): Transport
    {
        if (! $t->isHandledBy($by)) {
            throw ValidationException::withMessages(['transport' => 'Задача поручена не вам']);
        }
        if ($t->state !== TransportState::Moving) {
            throw ValidationException::withMessages(['transport' => $t->state->picked() ? 'Уже привезли' : 'Сначала «Забрал»']);
        }

        DB::transaction(function () use ($t, $by) {
            $t->update(['state' => TransportState::Delivered, 'delivered_at' => now()]);
            if ($offer = $t->saleOffer()) {
                ($this->place)($offer, ($t->destination ?? Point::Ours)->place(), $by);
            }
            $this->toYard($t, $by);
            // Привезли на стоянку логиста — очередь следующего отрезка маршрута (`Route::sync` откроет его и пошлёт поручение).
            Route::continueAt($t, $by);
            Route::sync($t->route_id, $by);
        });
        TransportMoved::dispatch($t, $by, 'delivered');

        return $t;
    }

    /**
     * ТС парковки привезли на парковку: другой город — перевод (`Transfer`), тот же город, другая парковка —
     * перестановка (`Move`); ТС ещё ожидается — ждём её там (приём — как обычно, на парковке). Зовёт и «Уже вывезли» (`Backdate`).
     */
    public function toYard(Transport $t, User $by): void
    {
        $vehicle = $t->parkVehicle();
        $yard = $t->toYard;
        if (! $vehicle || ! $yard || $vehicle->yard_id === $yard->id) {
            return;
        }
        if ($vehicle->state === VehicleState::Stored) {
            (int) $yard->settlement_id !== (int) $vehicle->city_id
                ? ($this->transfer)($vehicle, $by, $yard, null, now(), 'Логистика')
                : ($this->move)($vehicle, $by, $yard);
        } elseif ($vehicle->state === VehicleState::Expected) {
            $was = $vehicle->yard_id;
            $vehicle->update(['yard_id' => $yard->id]);
            $vehicle->log(EventType::Updated, $by, ['fields' => ['yard_id'], 'changes' => ['yard_id' => [$was, $yard->id]]]);
        }
    }
}
