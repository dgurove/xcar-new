<?php

namespace App\Http\Admin;

use App\Billing\InvoiceState;
use App\Support\Facets\Facets;

/**
 * Открытые счета подбора по машинам (18.11.2026 — своя вкладка «Оплата за подбор»; с 24.11.2026, владелец: «объединим», — часть
 * «Менеджер должен нам», `DealController::payingGroups`). Счета те же, что в «Оплатах → Комиссии» (`MoneyController::of`), только с
 * машиной: `ids` — машины вкладки, `unsentIds` — красный счётчик и группа «Выставить ссылку».
 */
class SelectionController
{
    /** Машина счёта: своя у счёта, иначе сделки, иначе гаража (`Invoice::carOffer`). */
    private const OFFER_SQL = 'coalesce(billing_invoices.offer_id, (select d.offer_id from deals d where d.id = billing_invoices.deal_id),
        (select g.offer_id from garage_cars g where g.invoice_id = billing_invoices.id limit 1))';

    /** Открытые счета подбора с машиной. */
    private static function open(?Facets $facets = null)
    {
        return MoneyController::of('fees', $facets)->where('state', InvoiceState::Issued)->whereRaw(self::OFFER_SQL.' is not null');
    }

    /** Машины вкладки — серое число колеса (`OfferController::scopeFor`). @return list<int> */
    public static function ids(): array
    {
        return self::open()->selectRaw(self::OFFER_SQL.' as offer_id')->distinct()->pluck('offer_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Машины, где ссылку ещё не выставили, а сделка на шаге оплаты, — красный счётчик. Неотправленный счёт в «Комиссиях» и есть
     * только такой (`Invoice::ON_PAY_STEP_SQL`). @return list<int>
     */
    public static function unsentIds(): array
    {
        return self::open()->whereNotNull('deal_id')->whereNull('shown_at')->where('paid', '<=', 0)
            ->selectRaw(self::OFFER_SQL.' as offer_id')->distinct()->pluck('offer_id')->map(fn ($id) => (int) $id)->all();
    }
}
