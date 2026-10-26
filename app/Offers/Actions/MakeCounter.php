<?php

namespace App\Offers\Actions;

use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\CounterState;
use App\Offers\Events\CounterOffered;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Контрпредложение (26.10.2026, начальник): админ в ответ на подтверждение называет свою цену — выше цены менеджера.
 * Менеджер соглашается (сразу сделка, `AnswerCounter`) или отказывается. Живое у предложения одно: новое другому менеджеру
 * снимает прежнее — иначе двое согласились бы на одну машину.
 */
final class MakeCounter
{
    public function __invoke(Bid $bid, User $by, int $amount, ?string $comment = null): Bid
    {
        return DB::transaction(function () use ($bid, $by, $amount, $comment) {
            $offer = Offer::whereKey($bid->offer_id)->lockForUpdate()->firstOrFail();
            $bid = Bid::whereKey($bid->id)->lockForUpdate()->firstOrFail();
            if ($bid->state !== BidState::Active || $bid->isGarage()) {
                throw ValidationException::withMessages(['counter' => 'Подтверждение уже '.mb_strtolower($bid->state->label())]);
            }
            if ($offer->deal()->exists()) {
                throw ValidationException::withMessages(['counter' => 'Идёт сделка — отдайте через «Отдать»']);
            }
            if ($amount <= (int) $bid->amount) {
                throw ValidationException::withMessages(['counter' => 'Контрпредложение — выше цены менеджера']);
            }

            $offer->bids()->whereKeyNot($bid->id)->where('counter_state', CounterState::Pending)->get()
                ->each(fn (Bid $other) => $this->withdraw($offer, $other, $by));

            $bid->update(['counter_amount' => $amount, 'counter_comment' => $comment ?: null, 'counter_by' => $by->id,
                'counter_at' => now(), 'counter_state' => CounterState::Pending]);
            $offer->log(OfferEventType::Counter, $by, ['bid_id' => $bid->id, 'amount' => $amount, 'manager' => $bid->user?->shortName()]);
            $bid->setRelation('offer', $offer);
            CounterOffered::dispatch($bid, $by);

            return $bid;
        });
    }

    /** Отозвать живое контрпредложение (своей кнопкой у строки или новым контром другому). */
    public function withdraw(Offer $offer, Bid $bid, User $by): void
    {
        if ($bid->counter_state !== CounterState::Pending) {
            return;
        }
        $bid->update(['counter_state' => CounterState::Withdrawn]);
        $offer->log(OfferEventType::Counter, $by, ['bid_id' => $bid->id, 'amount' => $bid->counter_amount, 'state' => 'withdrawn']);
    }
}
