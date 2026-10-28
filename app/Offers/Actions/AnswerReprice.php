<?php

namespace App\Offers\Actions;

use App\Notifications\RepriceAnsweredNotice;
use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\RepriceAsk;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Ответ на выросшую закупочную (28.10.2026, владелец): менеджер со страницы или из Telegram, админ — за него (устно).
 * - «Согласен»: подтверждение — `bids.cost` новая; сделка — новая закупочная, договор и неоплаченные счета
 *   (`ApplyReprice::updateDeal`), `supplier_cost` гаснет.
 * - «Отказываюсь»: подтверждение снимается; сделка отменяется, как «Я передумал» — предложение снова в продаже
 *   (`ChangeOfferState` → `Open`, внутри `CancelDeal`) по новой закупочной; к продаже не готово — в черновики.
 * Админам — кто что ответил (не нажавшему).
 */
final class AnswerReprice
{
    public function __construct(private ApplyReprice $apply, private ChangeOfferState $state) {}

    public function __invoke(RepriceAsk $ask, bool $agree, User $by): RepriceAsk
    {
        $staff = $by->id !== $ask->user_id;
        if ($staff) {
            abort_unless($by->canManageCrm(), 403);
        }

        DB::transaction(function () use ($ask, $agree, $by, $staff) {
            $offer = Offer::whereKey($ask->offer_id)->lockForUpdate()->firstOrFail();
            $ask = RepriceAsk::whereKey($ask->id)->lockForUpdate()->firstOrFail();
            if (! $ask->alive()) {
                throw ValidationException::withMessages(['reprice' => 'Вопрос о закупочной уже не действует']);
            }
            if ($agree) {
                if ($ask->bid_id) {
                    $ask->bid->update(['cost' => $ask->to_price]);
                } else {
                    if (! $ask->deal->moneyEditable()) {
                        throw ValidationException::withMessages(['reprice' => 'По счёту сделки уже платили — закупочную меняет админ в CRM']);
                    }
                    $this->apply->updateDeal($ask->deal, $ask->to_price, $by);
                }
            } elseif ($ask->bid_id) {
                // Снимается мимо `WithdrawBid`: после конца приёма отозвать своё он не может, а тут меняем мы.
                $ask->bid->update(['state' => BidState::Withdrawn]);
                $offer->log(OfferEventType::BidWithdrawn, $by, ['bid_id' => $ask->bid_id, 'reprice' => true]);
            } else {
                // Не готово к продаже (нет фото, марки) — в черновики: сделку отменить важнее, чем выставить.
                try {
                    DB::transaction(fn () => ($this->state)($offer, OfferState::Open, $by));
                } catch (ValidationException) {
                    ($this->state)($offer->fresh(), OfferState::Draft, $by);
                }
            }
            $ask->update(['state' => $agree ? RepriceAsk::AGREED : RepriceAsk::DECLINED, 'answered_at' => now(), 'answered_by' => $by->id]);
            $offer->log(OfferEventType::Reprice, $by, ['reprice' => $ask->reprice_id, 'state' => $agree ? 'agreed' : 'refused',
                'manager' => $ask->user?->shortName(), 'from' => $ask->from_price, 'to' => $ask->to_price, 'deal' => (bool) $ask->deal_id,
                ...($staff ? ['for' => 'manager'] : [])]);
        });

        $ask->refresh()->load(['offer', 'user']);
        DB::afterCommit(fn () => Notification::send(User::withRole(Role::Admin)->get()->reject(fn (User $u) => $u->is($by)), new RepriceAnsweredNotice($ask)));

        return $ask;
    }
}
