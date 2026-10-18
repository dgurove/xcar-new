<?php

namespace App\Offers\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\ChargeKind;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Garage\Car as GarageCar;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\Cancel;
use App\Logistics\Channel;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Jobs\RetractDealNotices;
use App\Users\User;
use App\Workflow\Requirement;
use Illuminate\Validation\ValidationException;

/**
 * Сделка сорвалась: закрыта, подтверждение победителя отклонено, просьбы к нему сняты,
 * невыплаченное вознаграждение и неоплаченные счета гаснут (оплаченное остаётся историей), ждущая машина гаражной уходит из гаража.
 */
final class CancelDeal
{
    /**
     * $keepCar — машину из гаража уберёт вызывающий сам («Отдали по ошибке», `ReturnFromGarage`); $keepBid — подтверждение
     * победителя снова ждёт среди прочих (12.10.2026, владелец: «отменяешь сделку и возвращаешь в продажу — пусть
     * подтверждение менеджера остаётся», `ReturnToSale`).
     */
    public function __invoke(Deal $deal, ?User $by = null, bool $keepCar = false, bool $keepBid = false): Deal
    {
        // Машина гаражной уже у менеджера или по ней есть расходы — молча её не снять (владелец 06.10.2026): разбираемся
        // руками, сделка остаётся.
        $car = $deal->isGarage() ? GarageCar::where('deal_id', $deal->id)->first() : null;
        if ($car && ! $keepCar && ($car->state !== CarState::Waiting || $car->costs()->exists())) {
            throw ValidationException::withMessages(['state' => $car->state === CarState::Waiting
                ? 'По ТС уже есть расходы: сначала уберите их'
                : 'ТС уже у менеджера: сделку так не отменить']);
        }
        $deal->update(['state' => DealState::Cancelled, 'closed_at' => now()]);
        // Отмена — тихо (как отказ): «принято», ход и сроки этой сделки у менеджера стираются из ленты и Telegram.
        RetractDealNotices::dispatch($deal->id);
        if ($deal->bid?->state === BidState::Accepted) {
            Bid::whereKey($deal->bid_id)->update($keepBid ? ['state' => BidState::Active, 'decided_at' => null, 'decided_by' => null] : ['state' => BidState::Declined]);
        }
        Requirement::where('deal_id', $deal->id)->whereNull('done_at')->update(['done_at' => now(), 'answer' => json_encode(['closed_by' => 'deal'])]);
        // Гаражная сорвалась, пока машину ещё не привезли («Отказываюсь», отказ поставщика, отдали другому) — из гаража долой.
        if ($car && ! $keepCar) {
            $car->delete();
            GarageChanged::dispatch($car);
        }
        // Вывоз в логистике (17.10.2026). Забирать должен был он сам и ещё не забрал — поручение снимается: кто вывозит
        // теперь, решает админ. Гаражная сорвалась — везти к нему больше некуда: точка снимается (уже везут — «Привёз»
        // поставит машину к нам).
        $offer = $deal->offer;
        $open = $offer ? Transport::openFor($offer) : null;
        if ($open && ! $open->state->picked()) {
            if ($open->channel === Channel::Manager && $open->executor_id === $deal->buyer_id) {
                app(AssignExecutor::class)->release($open, $by);
            }
            if ($car && ! $keepCar && $open->destination === Point::Keeper) {
                $open->update(['destination' => null]);
            }
            // Вывоз заказали ради этой сделки (заведён после принятия), а вендор не из тех, чьи машины вывозим все, — без
            // сделки он не нужен: отменяется, а не висит «Без исполнителя» (18.10.2026: тестовая сделка Кати).
            if (! $offer->vendor?->pickup_on_arrival && $open->created_at?->gte($deal->created_at) && ! $open->fresh()->state->picked()) {
                app(Cancel::class)($open->fresh(), $by, 'Сделка отменена', system: true);
            }
        }
        if (($fee = $deal->agentFee()->first()) && $fee->state === InvoiceState::Issued && $fee->paid == 0) {
            app(VoidInvoice::class)($fee, $by, 'Сделка отменена');
        }
        // Счёт менеджеру, за который ещё не платили (подбор по ДКП выставляется при принятии), гаснет вместе со сделкой —
        // и ссылка на оплату с ним (`CloseLinksWhenSettled`). Оплаченное — история: возврат решает человек.
        foreach ($deal->issuedInvoices()->where('state', InvoiceState::Issued)->where('kind', '!=', ChargeKind::Reward)->get() as $invoice) {
            if (! $invoice->payments()->whereNotIn('source', PaymentSource::soft())->exists()) {
                app(VoidInvoice::class)($invoice, $by, 'Сделка отменена');
            }
        }

        return $deal;
    }
}
