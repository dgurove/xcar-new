<?php

namespace App\Offers\Actions;

use App\Cars\Car;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;

final class CreateOffer
{
    /**
     * @param  array  $log  пометка в ленте о происхождении (например, закупка и ДЛ); `$by` null — завела почта (`AutoOffer`)
     * @param  ?Car  $car  машина, которую выставляют (ТС парковки, лот закупки); без неё — новая или найденная по номеру и
     *                     VIN (`Cars\OnCar`)
     */
    public function __invoke(?User $by, array $data = [], array $log = [], ?Car $car = null): Offer
    {
        $offer = new Offer;
        $car && $offer->setRelation('car', $car);
        $offer->fill($data);
        $offer->moderator_id = $by?->id;
        $offer->save();
        $offer->log(OfferEventType::Created, $by, $log);

        return $offer->refresh();
    }
}
