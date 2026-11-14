<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Сумма договора руками (14.11.2026, владелец: «админам и модераторам вручную удобно красиво менять сумму договора и счета
 * соответственно, всякое бывает»). Только у договора нашего юрлица (ПРАЙМ, Р.О.С.): счёт за ТС идёт за ней
 * (`SyncDealInvoices::prime`), сделка её больше не перетирает (`price_manual`). `null` — «Как в сделке»: снова по деньгам
 * сделки (`DealContract::priceFor`). По счёту уже платили — нельзя: перевыставлять нечего. В «Историю» — было → стало.
 */
final class SetContractPrice
{
    public function __construct(private SyncDealInvoices $invoices, private SettleContract $settle) {}

    public function __invoke(Deal $deal, ?int $price, User $by): DealContract
    {
        $contract = DealContract::for($deal);
        if (! $deal->isActive() || ! $deal->schemeOf()->paysCar() || $contract->form() === 'owner') {
            throw ValidationException::withMessages(['price' => 'У этой сделки сумму договора не меняют']);
        }
        if (! $deal->moneyEditable()) {
            throw ValidationException::withMessages(['price' => 'По счёту уже платили — сумму договора не сменить']);
        }
        if ($price !== null && $price < 1) {
            throw ValidationException::withMessages(['price' => 'Укажите сумму']);
        }
        $was = $contract->price;
        DB::transaction(function () use ($contract, $deal, $price, $by, $was) {
            $auto = $price === null;
            $contract->forceFill([
                'price' => $auto ? DealContract::priceFor($deal) : $price, 'price_manual' => ! $auto,
                'price_by' => $auto ? null : $by->id, 'price_at' => $auto ? null : now(),
            ])->save();
            if ($contract->wasChanged(['price', 'price_manual'])) {
                $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Сумма договора: '.($was ? Money::rub($was) : 'нет').' → '
                    .Money::rub((int) $contract->price).($auto ? ', как в сделке' : ', вручную')]);
            }
        });
        ($this->invoices)($deal->fresh(['offer', 'buyer', 'contract.buyer']), $by);

        return ($this->settle)($contract->fresh(['seller', 'buyer.party', 'deal.offer', 'deal.buyer']));
    }
}
