<?php

namespace App\Telegram\Messages;

use App\Billing\Invoice;
use App\Support\Money;
use App\Support\Surface;
use App\Telegram\Text;

/** Деньги пришли на Р.О.С. — перевести их Альфе (10.10.2026): редко, поэтому владельцу и админам в Telegram. */
final class RosTransferDue extends Message
{
    public function __construct(private Invoice $invoice) {}

    protected function title(): string
    {
        $deal = $this->invoice->deal;

        return 'Переведите '.Money::rub((int) ($deal?->supplierCost() ?? $this->invoice->total)).' '.($deal?->offer?->vendor?->name ?? 'поставщику').' с Р.О.С.';
    }

    protected function lines(): array
    {
        return Text::lines($this->invoice->deal?->offer, 'Оплачен счёт Р.О.С. '.$this->invoice->label().' на '.Money::rub($this->invoice->total));
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'В CRM', 'url' => Surface::Crm->url('/work/deals/'.$this->invoice->deal_id)];
    }
}
