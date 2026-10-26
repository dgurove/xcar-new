<?php

namespace App\Offers\Actions;

use App\Offers\Bid;
use App\Offers\CounterState;
use App\Offers\DealScheme;
use App\Offers\Events\CounterAnswered;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ответ менеджера на контрпредложение (26.10.2026). «Согласен» — цена подтверждения становится ценой админа и машина
 * сразу его: «Принять» от имени того, кто предложил (`AcceptBid`), деньги сделки — по умолчанию, админ правит их в сделке.
 * Цену назвал админ сам — согласия «не выше закупочной» второй раз не спрашиваем. «Отказаться» — подтверждение остаётся
 * по своей цене. Ответить можно и после конца приёма, пока машину никому не отдали.
 */
final class AnswerCounter
{
    public function __construct(private AcceptBid $accept) {}

    public function __invoke(Bid $bid, User $manager, bool $agree): Bid
    {
        if ($bid->user_id !== $manager->id) {
            throw ValidationException::withMessages(['counter' => 'Это не ваше подтверждение']);
        }

        DB::transaction(function () use ($bid, $manager, $agree) {
            $offer = Offer::whereKey($bid->offer_id)->lockForUpdate()->firstOrFail();
            $bid->refresh();
            if (! $bid->hasPendingCounter()) {
                throw ValidationException::withMessages(['counter' => 'Контрпредложение уже не действует']);
            }
            $amount = $bid->counter_amount;
            if (! $agree) {
                $bid->update(['counter_state' => CounterState::Declined]);
                $offer->log(OfferEventType::Counter, $manager, ['bid_id' => $bid->id, 'amount' => $amount, 'state' => 'declined']);

                return;
            }
            if ($offer->deal()->exists()) {
                throw ValidationException::withMessages(['counter' => 'Контрпредложение уже не действует']);
            }
            $bid->update(['amount' => $amount, 'counter_state' => CounterState::Accepted]);
            $offer->log(OfferEventType::Counter, $manager, ['bid_id' => $bid->id, 'amount' => $amount, 'state' => 'accepted']);
            ($this->accept)($bid, $bid->counterBy ?? $manager, scheme: DealScheme::resolve($offer, null), loss: true);
        });

        CounterAnswered::dispatch($bid->refresh(), $agree);

        return $bid;
    }
}
