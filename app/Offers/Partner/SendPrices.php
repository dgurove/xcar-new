<?php

namespace App\Offers\Partner;

use App\Http\Partner\MediaController;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * «Отправить» поставщика (08.11.2026, владелец: «автоматически не отправлять… после того как внесли закупочную — кнопка
 * „Отправить“»): вписанные им цены (`PartnerOffers::priced`) уходят к нам — из его списка предложение пропадает, в истории
 * «Поставщик отправил цену», дальше автоматика, что ждала (`Handover`). Одно предложение или все сразу.
 */
final class SendPrices
{
    /** @return int сколько отправлено */
    public function __invoke(User $user, ?Offer $only = null): int
    {
        $offers = PartnerOffers::priced(PartnerOffers::for($user))->when($only, fn ($q) => $q->whereKey($only->id))->get();
        foreach ($offers as $offer) {
            $held = $offer->heldByPartner();
            DB::transaction(function () use ($offer, $user) {
                $offer->forceFill(['partner_price_at' => null])->save();
                $offer->log(OfferEventType::Note, $user, ['text' => 'Поставщик отправил цену']);
            });
            Handover::after($offer, $held);
        }
        MediaController::forget($user->id);

        return $offers->count();
    }
}
