<?php

namespace App\Offers\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\ChargeKind;
use App\Billing\InvoiceState;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Поправить деньги сделки: схему оплаты, вознаграждение менеджера, гаражной — сколько нам по ссылке. Закупочная и сумма собственнику
 * закреплены с принятия и здесь не правятся (15.10.2026).
 * Пока за наши счета не платили (`Deal::moneyEditable`): счета, что ставятся сами, перевыставит `SyncDealInvoices`.
 */
final class UpdateDealMoney
{
    public function __construct(private SyncDealInvoices $invoices) {}

    /** @param  ?int  $amount  цена продажи (`deals.amount`, 18.11.2026: «цена продажи по итогу часто другая») — null: не трогаем */
    public function __invoke(Deal $deal, User $by, ?int $commission, ?DealScheme $scheme = null, ?int $share = null, ?string $note = null, ?int $amount = null): void
    {
        if (! $deal->moneyEditable()) {
            throw ValidationException::withMessages(['commission' => 'По счёту сделки уже платили, сначала отмените оплату']);
        }
        $price = $amount ?? $deal->amount;
        if ($commission !== null && $price !== null && $commission > $price) {
            throw ValidationException::withMessages(['commission' => 'Не больше цены продажи '.Money::rub($price)]);
        }
        $was = $deal->amount;
        $repriced = $amount !== null && $amount !== $was;
        $scheme ??= $deal->schemeOf();
        $selection = $scheme->paysSelection() && ! $deal->isGarage();
        $wasSelection = $deal->paysSelection();
        // Гаражная Совкомбанка (`namesBuyer`) — деньги как у обычной ПРАЙМ: вознаграждение считаем мы, когда менеджер продал.
        $garage = $deal->isGarage() && ! $deal->namesBuyer();
        DB::transaction(function () use ($deal, $by, $commission, $scheme, $selection, $wasSelection, $share, $garage, $note, $amount, $was, $repriced) {
            if ($repriced) {
                $deal->update(['amount' => $amount]);
                $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Цена продажи '.($was === null ? '' : Money::rub($was).' → ').Money::rub($amount)]);
            }
            $deal->update($garage
                ? ['scheme' => $scheme, 'share' => $deal->isGarageManager() ? $share : null]
                : ['commission' => $commission, 'scheme' => $scheme,
                    // Сумма собственнику закреплена с принятия, как и закупочная (15.10.2026, владелец: «никогда»).
                    'owner_price' => $selection ? ($deal->owner_price ?? $deal->cost) : null]);
            $deal->offer->log(OfferEventType::Note, $by, ['text' => $note ?? match (true) {
                // Гаражная «платит менеджер» — словами вознаграждения (16.11.2026): от разницы цены продажи и закупочной.
                $garage => ($deal->share && $deal->offer->asking_price && $deal->cost !== null
                    ? 'Вознаграждение менеджеру '.Money::rub((int) $deal->offer->asking_price - (int) $deal->cost - (int) $deal->share).', ' : '')
                    .'нам по ссылке '.($deal->share ? Money::rub($deal->share) : 'нет'),
                $selection => $scheme->payeeLabel().' '.Money::rub((int) $deal->ownerPrice()).', вознаграждение '.($commission ? Money::rub($commission) : 'нет'),
                default => $scheme->label().', вознаграждение '.($commission ? Money::rub($commission) : 'нет'),
            }]);
            // Ушли со схемы, где менеджер платит подбор, или стёрли гаражную долю — неоплаченный подбор гаснет (ссылка с ним).
            $fresh = $deal->fresh();
            // Договор — по новым деньгам: сумма собственнику или схема сменились, ДКП с прежней ценой не печатаем.
            // Сумма договора, вписанная руками (`SetContractPrice`), остаётся.
            $fresh->contract && ! $fresh->contract->price_manual && $fresh->contract->update(['price' => DealContract::priceFor($fresh)]);
            if (($wasSelection && ! $fresh->paysSelection()) || ($fresh->isGarageManager() && ! $fresh->share)) {
                foreach ($deal->issuedInvoices()->where('kind', ChargeKind::Selection)->where('state', InvoiceState::Issued)->get() as $old) {
                    if ($old->paidMoney() == 0) {
                        app(VoidInvoice::class)($old, $by, 'Схема оплаты изменена');
                    }
                }
            }
            ($this->invoices)($deal->fresh(), $by);
        });
    }
}
