<?php

namespace App\Http\Garage;

use App\Offers\Offer;
use App\Support\Surface;
use Illuminate\Http\Request;

/**
 * Вывоз, порученный менеджеру, с 17.10.2026 живёт в логистике: прежние ссылки `/garage/pickups/{n}` (уведомления,
 * Telegram) ведут на его задачу там; своя сделка — на сделку, своя гаражная машина — в гараж.
 */
class PickupController
{
    public function show(Request $request, Offer $offer)
    {
        $user = $request->user();
        $t = $offer->pickup();
        if ($t && ($t->isExecutedBy($user) || $user->isAdmin())) {
            return redirect()->away(Surface::Logistics->url('/transports/'.$t->id), 301);
        }
        if (($deal = $offer->deal) && $deal->buyer_id === $user->id) {
            return redirect($deal->href());
        }
        if (($car = $offer->garageCar) && $car->manager_id === $user->id) {
            return redirect('/garage/cars/'.$offer->number);
        }

        return redirect('/garage');
    }
}
