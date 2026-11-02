<?php

namespace App\Offers\Actions;

use App\Notifications\BidCorrectedNotice;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Админ правит опечатку в цене подтверждения (02.11.2026, владелец: «9 400 000 при цене 990 000 — очевидно 940 000, хочу
 * исправить карандашиком, а не отклонять»). Только живое и не гаражное; порогов нет — решение админа. Было → стало — в
 * «Истории», менеджеру — строкой ленты и в Telegram: цена его подтверждения теперь другая.
 */
final class CorrectBidAmount
{
    public function __invoke(Bid $bid, int $amount, User $by): Bid
    {
        if ($bid->state !== BidState::Active || $bid->isGarage()) {
            throw ValidationException::withMessages(['amount' => 'Поправить можно только живое подтверждение ценой']);
        }
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'Впишите цену']);
        }
        $was = (int) $bid->amount;
        if ($was === $amount) {
            return $bid;
        }
        DB::transaction(function () use ($bid, $amount, $was, $by) {
            $bid->update(['amount' => $amount]);
            $bid->offer->log(OfferEventType::Note, $by, ['text' => 'Подтверждение '.$bid->user->shortName().': цена поправлена '
                .Money::plain($was).' → '.Money::plain($amount)]);
        });
        $bid->user->notify(new BidCorrectedNotice($bid->fresh(['offer', 'user']), $was, $by));

        return $bid;
    }
}
