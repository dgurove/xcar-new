<?php

namespace App\Offers;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Offers\Actions\ApplyDealCredits;
use App\Support\Money;

/**
 * Разовая оплата по ссылке и сделка её машины словами (09.10.2026, владелец: «машина и есть сделка»): чья сделка у ТС,
 * в каком она состоянии и что будет, если привязать к ней ручную ссылку — до записи, в окне «ТС» карточки ссылки.
 * Сама запись — `AttachServiceCar` и `ApplyDealCredits`.
 */
final class DealCredit
{
    /** Сделка машины: живая, иначе последняя не отменённая. */
    public static function dealFor(Offer $offer): ?Deal
    {
        return Deal::where('offer_id', $offer->id)->where('state', DealState::Active)->first()
            ?? Deal::where('offer_id', $offer->id)->where('state', '!=', DealState::Cancelled)->latest('id')->first();
    }

    /** Остаток по счетам сделки, куда ложится зачёт (как `ApplyDealCredits`); null — счёта ещё нет. */
    public static function room(Deal $deal): ?float
    {
        $targets = Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('state', '!=', InvoiceState::Void)
            ->where('kind', '!=', ChargeKind::Reward)->with('claims')->get();
        if ($targets->isEmpty()) {
            return null;
        }

        return round($targets->sum(fn (Invoice $i) => max(0, $i->remaining() - $i->claimed())), 2);
    }

    /** Где сделка — для подсказки в поиске ТС: «ждёт ответа поставщика», «счёт 58 000 ₽», «оплачена». */
    public static function status(Deal $deal): string
    {
        $room = self::room($deal);

        return match (true) {
            $room === null => $deal->agreed() ? 'счёта ещё нет' : 'ждёт ответа поставщика',
            $room > 0 => 'к оплате '.Money::rub($room),
            default => 'оплачена',
        };
    }

    /** Что будет, если ручной счёт привязать к этой ТС, — одной фразой. */
    public static function preview(Invoice $manual, ?Offer $offer): string
    {
        if (! $offer) {
            return $manual->credit_deal_id ? 'Ссылка уйдёт из сделки, её зачёт снимем' : 'ТС не указано';
        }
        $deal = self::dealFor($offer);
        if (! $deal) {
            return 'Сделки по этой ТС нет, запишем только ТС';
        }
        $who = $deal->buyer?->shortName();
        $in = $who ? 'в сделку '.$who : 'в сделку';
        $paid = (float) $manual->paid;
        if ($paid <= 0) {
            return 'Засчитаем '.$in.', когда оплатят. Ссылка сделки тоже работает';
        }
        $room = self::room($deal);
        if ($room === null) {
            return 'Счёт сделки ещё не выставлен: засчитаем '.Money::rub($paid).', когда выставим';
        }
        // Уже засчитанное этой же ссылкой в эту сделку снова свободно: при привязке зачёт кладётся заново.
        if ($manual->credit_deal_id === $deal->id) {
            $room += app(ApplyDealCredits::class)->applied($manual);
        }
        $apply = min($paid, $room);
        $extra = round($paid - $apply, 2);

        return match (true) {
            $apply <= 0 => 'Счета сделки уже оплачены: '.Money::rub($paid).' будет сверх сделки',
            $extra > 0 => 'Засчитаем '.Money::rub($apply).' '.$in.', счёт будет оплачен, сверх сделки '.Money::rub($extra),
            $room - $apply < 0.01 => 'Засчитаем '.Money::rub($apply).' '.$in.', счёт будет оплачен',
            default => 'Засчитаем '.Money::rub($apply).' '.$in.', останется '.Money::rub($room - $apply),
        };
    }
}
