<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Реквизиты покупателя проверены (26.11.2026, владелец: «распознавание часто кривое, менеджеры отправляют как есть — надо, чтобы
 * Алина проверяла реквизиты»; «по сути там сразу и проверка и отправка должна быть, „Всё верно“ может любой сотрудник, это её
 * ответственность, а не привилегия»). Проверка — отпечаток данных (`DealContract::buyerSum`): данные поменялись — нужна снова.
 * Сотрудник вписал или поправил сам — проверено (`mark` из `DealContractController`). Без проверки «Отправить менеджеру» счёта за
 * ТС и договора не проходит (`gate`); письмо с реквизитами — из шторки проверки (`x-deal.buyer-check`).
 */
final class CheckBuyer
{
    public static function mark(Deal $deal, User $by): void
    {
        $contract = DealContract::where('deal_id', $deal->id)->first();
        $sum = $contract?->buyerSum();
        if (! $sum || $contract->buyer_checked_sum === $sum || ! $by->canEditDeals()) {
            return;
        }
        $contract->forceFill(['buyer_checked_at' => now(), 'buyer_checked_by' => $by->id, 'buyer_checked_sum' => $sum])->save();
        $deal->offer?->log(OfferEventType::Note, $by, ['text' => 'Реквизиты покупателя проверены']);
        \App\Support\Nav::forgetStaffCounts();
    }

    /** Отправить менеджеру то, где реквизиты покупателя, — только проверенными. */
    public static function gate(Deal $deal): void
    {
        if (DealContract::where('deal_id', $deal->id)->first()?->needsBuyerCheck()) {
            throw ValidationException::withMessages(['buyer' => 'Сначала проверьте реквизиты покупателя']);
        }
    }

    /** Счёт выставлен на покупателя — в нём его реквизиты. */
    public static function onInvoice(Deal $deal, \App\Billing\Invoice $invoice): bool
    {
        $party = DealContract::where('deal_id', $deal->id)->first()?->buyerParty();

        return $party !== null && $invoice->party_id === $party->id;
    }
}
