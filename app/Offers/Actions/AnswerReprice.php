<?php

namespace App\Offers\Actions;

use App\Notifications\RepriceAnsweredNotice;
use App\Notifications\RepriceDecidedNotice;
use App\Offers\BidState;
use App\Offers\Binding;
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
 * Ответ на новую закупочную (28.10.2026, владелец): менеджер со страницы или из Telegram, админ — за него (устно).
 *
 * С 10.11.2026 ответ — **его новая цена подтверждения** (владелец по 0790/046/09068/26: «Согласен» менял только закупочную, и
 * сделка стояла «в минус 110 000»; «его просит ввести новую цену подтверждения, он вводит, и админы смотрят и либо принимают,
 * либо контрпредложение, либо отказ»):
 * - `offer`: цена по зонам подтверждения (`PlaceBid::checkZone` от новой закупочной). Подтверждение — его цена и новая
 *   закупочная сразу (`bids.amount`, `bids.cost`), дальше решаем, как по любому подтверждению. Сделка — `offered`, решаем мы;
 *   админ за менеджера — сразу `settle`.
 * - `accept` (админ, `offered`): сделка — новая закупочная и его цена (`ApplyReprice::updateDeal`: договор, неоплаченные
 *   счета), `supplier_cost` гаснет, показ всем снимается.
 * - `counter` (админ, `offered`): наша цена, ход снова его (`countered`, срок заново). `agree` — сходимся на ней;
 *   `refuseCounter` — снова его цена (`offered`), решаем заново, как у контрпредложения по подтверждению.
 * - `decline`: менеджер отказался или админ отказал — подтверждение снимается; сделка отменяется, как «Я передумал» —
 *   предложение снова в продаже (`ChangeOfferState` → `Open`, внутри `CancelDeal`); к продаже не готово — в черновики.
 *   Показывали всем, пока думал (`ShowDealToAll`, 04.11.2026), — приём до конца показа.
 * Админам — что ответил менеджер (`RepriceAnsweredNotice`), менеджеру — что решили мы (`RepriceDecidedNotice`).
 */
final class AnswerReprice
{
    public function __construct(private ApplyReprice $apply, private ChangeOfferState $state, private ShowDealToAll $showAll) {}

    /** Менеджер (или админ за него) назвал новую цену подтверждения. */
    public function offer(RepriceAsk $ask, int $price, User $by, bool $lowOk = false): RepriceAsk
    {
        $staff = $this->staff($ask, $by);

        $ask = $this->locked($ask, function (RepriceAsk $ask, Offer $offer) use ($price, $by, $staff, $lowOk) {
            if (! $ask->hisTurn()) {
                throw ValidationException::withMessages(['reprice' => 'Цену уже назвали — ждите решения']);
            }
            // Админ за менеджера решает сам — порог ему не помеха, но ниже новой закупочной без «в минус» не пускаем.
            $staff ? $this->notBelowCost($ask, $price) : PlaceBid::checkZone($offer, $price, $lowOk);
            if ($ask->bid_id) {
                $ask->bid->update(['amount' => $price, 'cost' => $ask->to_price]);
                $this->close($ask, $offer, $by, RepriceAsk::AGREED, $staff, $price);

                return;
            }
            $ask->update(['state' => RepriceAsk::OFFERED, 'offered_price' => $price, 'offered_at' => now(), 'counter_price' => null, 'counter_at' => null]);
            if ($staff) {
                $this->settle($ask, $offer, $by, $price, true);
            } else {
                $offer->log(OfferEventType::Reprice, $by, ['reprice' => $ask->reprice_id, 'state' => 'offered', 'manager' => $ask->user?->shortName(),
                    'from' => $ask->from_price, 'to' => $ask->to_price, 'price' => $price, 'deal' => true]);
            }
        });
        $this->tellAdmins($ask, $by);

        return $ask;
    }

    /** Админ принял цену менеджера по сделке. */
    public function accept(RepriceAsk $ask, User $by): RepriceAsk
    {
        abort_unless($by->canManageCrm(), 403);
        $ask = $this->locked($ask, function (RepriceAsk $ask, Offer $offer) use ($by) {
            if (! $ask->isOffered()) {
                throw ValidationException::withMessages(['reprice' => 'Менеджер ещё не назвал цену']);
            }
            $this->settle($ask, $offer, $by, $ask->offered_price, false);
        });
        $ask->user?->notify(new RepriceDecidedNotice($ask, RepriceDecidedNotice::ACCEPTED));

        return $ask;
    }

    /** Контрпредложение админа на цену менеджера по сделке. */
    public function counter(RepriceAsk $ask, int $price, User $by): RepriceAsk
    {
        abort_unless($by->canManageCrm(), 403);
        $ask = $this->locked($ask, function (RepriceAsk $ask, Offer $offer) use ($price, $by) {
            if (! $ask->isOffered()) {
                throw ValidationException::withMessages(['reprice' => 'Менеджер ещё не назвал цену']);
            }
            if ($price <= $ask->offered_price) {
                throw ValidationException::withMessages(['counter_price' => 'Контрпредложение — выше его цены '.\App\Support\Money::rub($ask->offered_price)]);
            }
            $ask->update(['state' => RepriceAsk::COUNTERED, 'counter_price' => $price, 'counter_at' => now(), 'due_at' => RepriceAsk::dueFrom(now())]);
            $offer->log(OfferEventType::Reprice, $by, ['reprice' => $ask->reprice_id, 'state' => 'countered', 'manager' => $ask->user?->shortName(),
                'price' => $ask->offered_price, 'counter' => $price, 'deal' => true]);
        });
        $ask->user?->notify(new RepriceDecidedNotice($ask, RepriceDecidedNotice::COUNTERED));

        return $ask;
    }

    /** Менеджер (или админ за него) согласен с нашим контрпредложением. */
    public function agree(RepriceAsk $ask, User $by): RepriceAsk
    {
        $staff = $this->staff($ask, $by);
        $ask = $this->locked($ask, function (RepriceAsk $ask, Offer $offer) use ($by, $staff) {
            if (! $ask->isCountered()) {
                throw ValidationException::withMessages(['reprice' => 'Контрпредложения нет — назовите свою цену']);
            }
            $this->settle($ask, $offer, $by, $ask->counter_price, $staff);
        });
        $this->tellAdmins($ask, $by);

        return $ask;
    }

    /** Менеджер не согласен с контрпредложением — в силе его цена, решаем снова. */
    public function refuseCounter(RepriceAsk $ask, User $by): RepriceAsk
    {
        $staff = $this->staff($ask, $by);
        $ask = $this->locked($ask, function (RepriceAsk $ask, Offer $offer) use ($by, $staff) {
            if (! $ask->isCountered()) {
                throw ValidationException::withMessages(['reprice' => 'Контрпредложения нет']);
            }
            $offer->log(OfferEventType::Reprice, $by, ['reprice' => $ask->reprice_id, 'state' => 'counter_refused', 'manager' => $ask->user?->shortName(),
                'price' => $ask->offered_price, 'counter' => $ask->counter_price, 'deal' => true, ...($staff ? ['for' => 'manager'] : [])]);
            $ask->update(['state' => RepriceAsk::OFFERED, 'offered_at' => now()]);
        });
        $this->tellAdmins($ask, $by);

        return $ask;
    }

    /** Отказ: менеджер не берёт по новой цене или админ отказал ему. */
    public function decline(RepriceAsk $ask, User $by): RepriceAsk
    {
        $staff = $this->staff($ask, $by);
        // Админ отказал по названной цене — это наше решение, сказать ему; иначе отказался он (или за него) — сказать админам.
        $rejected = $staff && RepriceAsk::find($ask->id)?->isOffered();
        $ask = $this->locked($ask, function (RepriceAsk $ask, Offer $offer) use ($by, $staff) {
            if ($ask->bid_id) {
                // Снимается мимо `WithdrawBid`: после конца приёма отозвать своё он не может, а тут меняем мы.
                $ask->bid->update(['state' => BidState::Withdrawn]);
                $offer->log(OfferEventType::BidWithdrawn, $by, ['bid_id' => $ask->bid_id, 'reprice' => true]);
            } else {
                // Не готово к продаже (нет фото, марки) — в черновики: сделку отменить важнее, чем выставить.
                // Показывали всем, пока думал (04.11.2026), — приём идёт до конца показа, резервы — живые подтверждения.
                $until = $offer->showsDealAsOpen() ? $offer->deal_show_until : null;
                try {
                    DB::transaction(fn () => ($this->state)($offer, OfferState::Open, $by));
                    $until && $offer->refresh()->update(['bids_close_at' => Binding::clampClose($offer, $until)]);
                } catch (ValidationException) {
                    ($this->state)($offer->fresh(), OfferState::Draft, $by);
                }
            }
            $this->close($ask, $offer, $by, RepriceAsk::DECLINED, $staff);
        });
        $rejected ? $ask->user?->notify(new RepriceDecidedNotice($ask, RepriceDecidedNotice::REJECTED)) : $this->tellAdmins($ask, $by);

        return $ask;
    }

    /** Сходимся по сделке на цене: новая закупочная и его цена, договор и счета, показ всем снят. */
    private function settle(RepriceAsk $ask, Offer $offer, User $by, int $price, bool $forManager): void
    {
        if (! $ask->deal->moneyEditable()) {
            throw ValidationException::withMessages(['reprice' => 'По счёту сделки уже платили — закупочную меняет админ в CRM']);
        }
        $this->notBelowCost($ask, $price);
        $this->apply->updateDeal($ask->deal, $ask->to_price, $by, $price);
        // Показывали всем, пока думал (04.11.2026), — сошлись: машина сразу уходит из предложений.
        $this->showAll->stop($offer, $by, 'Сошлись с менеджером по новой цене — показ всем снят');
        $this->close($ask, $offer, $by, RepriceAsk::AGREED, $forManager, $price);
    }

    private function close(RepriceAsk $ask, Offer $offer, User $by, string $state, bool $forManager, ?int $price = null): void
    {
        $ask->update(['state' => $state, 'answered_at' => now(), 'answered_by' => $by->id]);
        $offer->log(OfferEventType::Reprice, $by, ['reprice' => $ask->reprice_id, 'state' => $state === RepriceAsk::AGREED ? 'agreed' : 'refused',
            'manager' => $ask->user?->shortName(), 'from' => $ask->from_price, 'to' => $ask->to_price, 'deal' => (bool) $ask->deal_id,
            ...($price !== null ? ['price' => $price] : []), ...($forManager ? ['for' => 'manager'] : [])]);
    }

    /** Цена подтверждения не ниже новой закупочной: иначе сделка в минус. */
    private function notBelowCost(RepriceAsk $ask, int $price): void
    {
        if ($price < $ask->to_price) {
            throw ValidationException::withMessages(['reprice_price' => 'Ниже новой закупочной '.\App\Support\Money::rub($ask->to_price)]);
        }
    }

    /** Отвечает не сам менеджер — только админ CRM за него. */
    private function staff(RepriceAsk $ask, User $by): bool
    {
        $staff = $by->id !== $ask->user_id;
        abort_if($staff && ! $by->canManageCrm(), 403);

        return $staff;
    }

    /** @param  callable(RepriceAsk, Offer): void  $do */
    private function locked(RepriceAsk $ask, callable $do): RepriceAsk
    {
        DB::transaction(function () use ($ask, $do) {
            $offer = Offer::whereKey($ask->offer_id)->lockForUpdate()->firstOrFail();
            $ask = RepriceAsk::whereKey($ask->id)->lockForUpdate()->firstOrFail();
            if (! $ask->alive()) {
                throw ValidationException::withMessages(['reprice' => 'Вопрос о закупочной уже не действует']);
            }
            $do($ask, $offer);
        });

        return $ask->refresh()->load(['offer', 'user', 'deal', 'bid']);
    }

    /** Админам (кроме нажавшего) — что ответил менеджер. */
    private function tellAdmins(RepriceAsk $ask, User $by): void
    {
        DB::afterCommit(fn () => Notification::send(User::withRole(Role::Admin)->get()->reject(fn (User $u) => $u->is($by)), new RepriceAnsweredNotice($ask)));
    }
}
