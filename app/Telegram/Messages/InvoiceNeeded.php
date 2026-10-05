<?php

namespace App\Telegram\Messages;

use App\Offers\Deal;
use App\Support\Money;
use App\Support\Surface;
use App\Telegram\Text;

/** Владельцу: сделка дошла до оплаты, счёта нет — кнопка ведёт прямо в «Выставить счёт». */
final class InvoiceNeeded extends Message
{
    public function __construct(private Deal $deal, private ?string $gap = null) {}

    protected function title(): string
    {
        return ($this->gap === 'share' ? 'Впишите нашу долю: ' : 'Выставите счёт: ').$this->deal->offer->titleWithYear();
    }

    protected function lines(): array
    {
        return Text::lines($this->deal->offer,
            $this->deal->buyer ? 'Менеджер '.$this->deal->buyer->name : null,
            $this->deal->amount ? 'Цена подтверждения '.Money::rub($this->deal->amount) : null);
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return $this->gap === 'share'
            ? ['text' => 'Вписать долю', 'url' => Surface::Crm->url('/work/deals/'.$this->deal->id.'#money')]
            : ['text' => 'Выставить счёт', 'url' => Surface::Crm->url('/work/invoices/new?offer='.$this->deal->offer->number)];
    }
}
