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

    /**
     * @param  ?int  $amount  цена продажи (`deals.amount`, 18.11.2026: «цена продажи по итогу часто другая») — null: не трогаем
     * @param  ?int  $cash  часть наличными мимо счёта (`deal_contracts.cash_part`, 18.11.2026 вместо суммы договора руками); трогаем,
     *                      только если `$setCash`
     */
    public function __invoke(Deal $deal, User $by, ?int $commission, ?DealScheme $scheme = null, ?int $share = null, ?string $note = null, ?int $amount = null,
        ?int $cash = null, bool $setCash = false): void
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
        $cash = $cash ?: null;
        if ($setCash && $cash !== null && ($price === null || $cash > $price - (int) $commission)) {
            throw ValidationException::withMessages(['cash_part' => 'Не больше цены продажи за вычетом вознаграждения']);
        }
        $scheme ??= $deal->schemeOf();
        $selection = $scheme->paysSelection() && ! $deal->isGarage();
        $wasSelection = $deal->paysSelection();
        // Гаражная Совкомбанка (`namesBuyer`) — деньги как у обычной ПРАЙМ: вознаграждение считаем мы, когда менеджер продал.
        $garage = $deal->isGarage() && ! $deal->namesBuyer();
        DB::transaction(function () use ($deal, $by, $commission, $scheme, $selection, $wasSelection, $share, $garage, $note, $amount, $was, $repriced, $cash, $setCash) {
            // Наличными мимо счёта — договор и счёт за ТС меньше на эту часть (`DealContract::priceFor`).
            if ($setCash && $deal->contract && $deal->contract->cash_part !== $cash) {
                $deal->contract->update(['cash_part' => $cash]);
                $deal->offer->log(OfferEventType::Note, $by, ['text' => $cash ? 'Наличными мимо счёта '.Money::rub($cash) : 'Наличных мимо счёта нет']);
            }
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
            $fresh->contract && $fresh->contract->update(['price' => DealContract::priceFor($fresh)]);
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
