<?php

namespace App\Offers\Actions;

use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Подтверждение за менеджера (05.10.2026, владелец: менеджер без интернета — админ вносит сам). Встаёт в список с
 * пометкой «внёс …», дальше его принимают обычным «Принять». В обход правил менеджера (решение владельца): и после
 * закрытия приёма, и ниже минимальной, и тому, кому предложение не показывали. Менеджеру ничего не шлём до принятия.
 */
final class PlaceBidFor
{
    public function __invoke(Offer $offer, User $manager, User $by, ?int $amount, BidKind $kind = BidKind::Buyer, ?string $comment = null): Bid
    {
        if (! $by->canManageCrm()) {
            throw ValidationException::withMessages(['amount' => 'Вносить за менеджера может только администратор']);
        }
        if (! $manager->canBid()) {
            throw ValidationException::withMessages(['manager_id' => 'Это не менеджер']);
        }

        return DB::transaction(function () use ($offer, $manager, $by, $amount, $kind, $comment) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if (! in_array($offer->state, [OfferState::Open, OfferState::Sold], true)) {
                throw ValidationException::withMessages(['amount' => 'Предложение не в продаже']);
            }
            if ($kind === BidKind::Garage) {
                if (! $offer->garageAllowedFor($manager)) {
                    throw ValidationException::withMessages(['amount' => 'Эту машину в гараж не берут']);
                }
                $amount = null;
            } elseif (! $amount) {
                throw ValidationException::withMessages(['amount' => 'Впишите цену']);
            }

            return PlaceBid::record($offer, $manager, $by, $amount, $comment, $kind);
        });
    }
}
