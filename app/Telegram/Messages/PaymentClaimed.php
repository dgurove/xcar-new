<?php

namespace App\Telegram\Messages;

use App\Billing\Payment;
use App\Garage\Car;
use App\Support\Money;
use App\Support\Surface;
use App\Telegram\Text;

/** Менеджер сообщил об оплате счёта: кто, сколько, платёжка; «Поступило» подтверждает одним нажатием. */
final class PaymentClaimed extends Message
{
    public function __construct(private Payment $payment) {}

    protected function title(): string
    {
        $i = $this->payment->invoice;
        $offer = $i->deal?->offer ?? Car::ofInvoice($i)?->offer;

        return ($i->deal?->buyer?->name ?? $i->party->name).' сообщил об оплате'.($offer ? ': '.$offer->titleWithYear() : '');
    }

    protected function lines(): array
    {
        $p = $this->payment;
        $i = $p->invoice;

        return Text::lines($i->deal?->offer ?? Car::ofInvoice($i)?->offer,
            Money::rub($p->amount).' от '.$p->paid_at->translatedFormat('j M').($p->ref ? ', п/п № '.$p->ref : '').($p->slip() ? ', платёжка приложена' : ''),
            'Счёт '.$i->label().', '.$i->party->name);
    }

    protected function decisions(): array
    {
        return [['text' => 'Поступило', 'callback_data' => 'claim:'.$this->payment->id.':ok']];
    }

    protected function link(): array
    {
        // Гаражный счёт живёт на машине в гараже: в «Деньгах» CRM только счета сделок.
        if ($car = Car::ofInvoice($this->payment->invoice)) {
            return ['text' => 'Машина в гараже', 'url' => $car->url()];
        }

        return ['text' => 'Счёт в CRM', 'url' => Surface::Crm->url('/work/money/invoices/'.$this->payment->invoice_id)];
    }
}
