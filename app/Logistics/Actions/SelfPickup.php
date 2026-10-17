<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportMoved;
use App\Logistics\Executor;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;
use Illuminate\Support\Facades\DB;

/**
 * Менеджер забрал машину сам — «Автомобиль забрал» в своей сделке на xcar.ru или «Забрал» в гараже (17.10.2026, владелец:
 * «он сам вывозит через CRM либо вообще покупатель вывозит; в логистике не надо отображать»). Его задача (или без исполнителя)
 * закрывается «Привёз» к нему — в логистике её не видно (`Transport::scopeInLogistics`), машина «у менеджера»; задачу, что
 * уже вёл логист или наши, отменяем: машина уехала без них.
 */
final class SelfPickup
{
    public function __construct(private SetCarPlace $place, private Cancel $cancel) {}

    public function __invoke(Offer $offer, User $manager, User $by): ?Transport
    {
        $t = Transport::openFor($offer);
        if (! $t || ! $t->isPickup() || $t->state->picked()) {
            return $t;
        }
        if ($t->executor !== null && $t->executor !== Executor::Manager) {
            return ($this->cancel)($t, $by, 'Менеджер забрал сам', system: true);
        }
        DB::transaction(function () use ($t, $manager, $offer, $by) {
            $t->update([
                'executor' => Executor::Manager, 'executor_id' => $manager->id, 'coordinator_id' => null, 'docs_by_cdek' => false,
                'state' => TransportState::Delivered, 'assigned_at' => $t->assigned_at ?? now(), 'picked_at' => now(), 'delivered_at' => now(),
                'destination' => Point::Keeper,
            ]);
            ($this->place)($offer, CarPlace::Keeper, $by);
        });
        TransportMoved::dispatch($t, $by, 'picked');
        TransportMoved::dispatch($t, $by, 'delivered');

        return $t;
    }
}
