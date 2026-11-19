<?php

namespace App\Offers\Actions;

use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\CounterState;
use App\Offers\DealScheme;
use App\Offers\Giving;
use App\Offers\Events\CounterAnswered;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ответ менеджера на контрпредложение (26.10.2026). «Согласен» — цена подтверждения становится ценой админа и машина
 * сразу его: «Принять» от имени того, кто предложил (`AcceptBid`), деньги сделки — по умолчанию, админ правит их в сделке.
 * При идущей сделке (резерв, 19.11.2026) — передача ему полностью через `Give`, прежняя отменяется.
 * Цену назвал админ сам — согласия «не выше закупочной» второй раз не спрашиваем. «Отказаться» — подтверждение снимается
 * (19.11.2026, владелец: «остаётся его цена — как-то неправильно»; прежде оставалось по своей цене), и после конца приёма тоже:
 * отказ — его ответ, а не отзыв в приёме (`WithdrawBid` после приёма не пускает). Ответить можно и после конца приёма, пока машину никому не отдали. `$staff` — админ отвечает за менеджера
 * (28.10.2026, владелец: «устно подтвердил»): в «Истории» — «за менеджера», админам уведомление не нажавшему.
 */
final class AnswerCounter
{
    public function __construct(private AcceptBid $accept) {}

    public function __invoke(Bid $bid, User $manager, bool $agree, ?User $staff = null): Bid
    {
        if ($staff) {
            abort_unless($staff->canManageCrm(), 403);
            $manager = $bid->user;
        } elseif ($bid->user_id !== $manager->id) {
            throw ValidationException::withMessages(['counter' => 'Это не ваше подтверждение']);
        }
        $for = $staff ? ['for' => 'manager'] : [];

        $stuck = null;
        DB::transaction(function () use ($bid, $manager, $agree, $staff, $for, &$stuck) {
            $offer = Offer::whereKey($bid->offer_id)->lockForUpdate()->firstOrFail();
            $bid->refresh();
            if (! $bid->hasPendingCounter()) {
                throw ValidationException::withMessages(['counter' => 'Контрпредложение уже не действует']);
            }
            $amount = $bid->counter_amount;
            if (! $agree) {
                $bid->update(['counter_state' => CounterState::Declined, 'counter_remind_at' => null] + ($bid->state === BidState::Active ? ['state' => BidState::Withdrawn] : []));
                $offer->log(OfferEventType::Counter, $staff ?? $manager, ['bid_id' => $bid->id, 'amount' => $amount, 'state' => 'declined', ...$for]);
                $bid->state === BidState::Withdrawn && $offer->log(OfferEventType::BidWithdrawn, $staff ?? $manager, ['bid_id' => $bid->id, ...$for]);

                return;
            }
            $bid->update(['amount' => $amount, 'counter_state' => CounterState::Accepted, 'counter_remind_at' => null]);
            $offer->log(OfferEventType::Counter, $staff ?? $manager, ['bid_id' => $bid->id, 'amount' => $amount, 'state' => 'accepted', ...$for]);
            $scheme = DealScheme::resolve($offer, null);
            if (! $offer->deal()->exists()) {
                ($this->accept)($bid, $bid->counterBy ?? $manager, scheme: $scheme, loss: true);

                return;
            }
            // Резерв при идущей сделке (19.11.2026, владелец: «сразу ему, сделка Тужикова отменяется») — как «Отдать» админом:
            // прежняя сделка отменяется. Не пустило (машина уже в гараже, гараж не включён…) — цена новая, согласие записано,
            // админам — передать руками; менеджер ошибки не видит, его ответ принят.
            try {
                app(Give::class)(Giving::bid($bid, scheme: $scheme, loss: true), $bid->counterBy ?? $staff ?? $manager);
            } catch (ValidationException $e) {
                $stuck = collect($e->errors())->flatten()->first() ?: 'не вышло';
            }
        });

        CounterAnswered::dispatch($bid->refresh(), $agree, $staff, $stuck);

        return $bid;
    }
}
