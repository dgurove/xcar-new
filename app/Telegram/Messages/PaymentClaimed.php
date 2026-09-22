<?php

namespace App\Telegram\Messages;

use App\Billing\Payment;
use App\Support\Money;
use App\Support\Surface;

/** Менеджер сообщил об оплате счёта: кто, сколько, платёжка; «Поступило» подтверждает одним нажатием. */
final class PaymentClaimed extends Message
{
    public function __construct(private Payment $payment) {}

    protected function title(): string
    {
        return 'Сообщил об оплате: '.($this->payment->invoice->deal?->buyer?->name ?? $this->payment->invoice->party->name);
    }

    protected function lines(): array
    {
        $p = $this->payment;
        $i = $p->invoice;

        return [
            'Счёт '.$i->label().', '.$i->party->name,
            Money::rub($p->amount).' от '.$p->paid_at->translatedFormat('j M').($p->ref ? ', п/п № '.$p->ref : '').($p->slip() ? ', платёжка приложена' : ''),
            $i->deal?->offer?->titleWithYear(),
        ];
    }

    protected function decisions(): array
    {
        return [['text' => 'Поступило', 'callback_data' => 'claim:'.$this->payment->id.':ok']];
    }

    protected function link(): array
    {
        return ['text' => 'Счёт в CRM', 'url' => Surface::Crm->url('/work/money/invoices/'.$this->payment->invoice_id)];
    }
}
