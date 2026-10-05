<?php

namespace App\Telegram\Messages;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\PayMethod;
use App\Billing\Invoice;
use App\Garage\Car;
use App\Support\Money;
use App\Support\Surface;
use App\Telegram\Text;

/**
 * Оплата по ссылке — владельцу: оплачено, переплата к возврату, чек не пробился. Машина в заголовке, ниже сумма, счёт и
 * менеджер; кнопка — счёт в CRM, где ссылка, попытки и «Вернуть».
 */
final class OnlinePayment extends Message
{
    public function __construct(private AcquiringPayment $attempt, private string $what) {}

    private function invoice(): Invoice
    {
        return $this->attempt->link->invoice;
    }

    protected function title(): string
    {
        $i = $this->invoice();
        $offer = $i->deal?->offer ?? Car::ofInvoice($i)?->offer;
        $head = match ($this->what) {
            'overpaid' => 'Переплата по ссылке, нужно вернуть',
            'receipt' => 'Чек по оплате не пробился',
            default => 'Оплачено по ссылке',
        };

        return $head.($offer ? ': '.$offer->titleWithYear() : '');
    }

    protected function lines(): array
    {
        $a = $this->attempt;
        $i = $this->invoice();
        $sum = match ($this->what) {
            'overpaid' => Money::rub($a->overpaid()).' сверх счёта',
            default => Money::rub($a->amount).' '.PayMethod::label($a->method),
        };

        return Text::lines($i->deal?->offer ?? Car::ofInvoice($i)?->offer,
            $sum,
            'Счёт '.$i->label().', '.$i->party->name.($i->remaining() > 0 && $this->what === 'paid' ? ', остаток '.Money::rub($i->remaining()) : ''),
            $i->deal?->buyer ? 'Менеджер '.$i->deal->buyer->name : null);
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'Счёт в CRM', 'url' => Surface::Crm->url('/work/money/invoices/'.$this->invoice()->id)];
    }
}
