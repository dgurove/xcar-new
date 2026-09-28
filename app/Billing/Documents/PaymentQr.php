<?php

namespace App\Billing\Documents;

use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\PaymentSource;

/**
 * Строка QR платёжки по ГОСТ Р 56042 (ST00012, UTF-8): приложение любого банка заполняет перевод само —
 * получатель, счёт, сумма к оплате, назначение «Оплата по счёту № N от …», по которому выписка потом
 * находит счёт. Без наших банковских реквизитов QR не печатается.
 */
final class PaymentQr
{
    public static function text(Invoice $invoice, ?Party $self = null): ?string
    {
        $self ??= Party::self();
        if ($self->bankMissing() || $invoice->isOwed() || ! $invoice->number) {
            return null;
        }
        $offset = $invoice->relationLoaded('payments')
            ? $invoice->payments->where('source', PaymentSource::Offset)->sum('amount')
            : $invoice->payments()->where('source', PaymentSource::Offset)->sum('amount');
        $due = round($invoice->total - $offset, 2);
        $fields = array_filter([
            'Name' => $self->name,
            'PersonalAcc' => $self->account,
            'BankName' => $self->bank_name,
            'BIC' => $self->bik,
            'CorrespAcc' => $self->corr_account ?: '0',
            'PayeeINN' => $self->inn,
            'KPP' => $self->kpp,
            'Sum' => (string) (int) round($due * 100),
            'Purpose' => self::purpose($invoice),
        ], fn ($v) => $v !== null && $v !== '');

        return 'ST00012|'.implode('|', array_map(fn ($k, $v) => $k.'='.str_replace('|', '/', $v), array_keys($fields), $fields));
    }

    /** Назначение платежа — то же, что ищет `MatchTransaction`. */
    public static function purpose(Invoice $invoice): string
    {
        return 'Оплата по счёту № '.$invoice->number.' от '.$invoice->issued_at->format('d.m.Y').'. '
            .($invoice->vat ? 'В т.ч. НДС '.Invoice::VAT.'%' : 'НДС не облагается');
    }
}
