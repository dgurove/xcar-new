<?php

namespace App\Telegram\Messages;

use App\Billing\Invoice;
use App\Support\Money;
use App\Support\Surface;

/** Счёт просрочен: контрагент, остаток, дней; «Оплачен» закрывает остаток одним нажатием. */
final class InvoiceOverdue extends Message
{
    public function __construct(private Invoice $invoice) {}

    protected function title(): string
    {
        return ($this->invoice->isOwed() ? 'Мы просрочили ' : 'Счёт просрочен ').$this->invoice->label();
    }

    protected function lines(): array
    {
        $i = $this->invoice;

        return [
            $i->party->name,
            'Остаток '.Money::rub($i->remaining()).' из '.Money::rub($i->total),
            'Срок '.$i->due_at->translatedFormat('j M').', '.$i->overdueDays().' дн назад',
            $i->vehicle?->titleWithYear(),
        ];
    }

    protected function decisions(): array
    {
        return [['text' => $this->invoice->isOwed() ? 'Перечислено' : 'Оплачен', 'callback_data' => 'invoice:'.$this->invoice->id.':paid']];
    }

    protected function link(): array
    {
        return ['text' => 'Счёт на стоянке', 'url' => Surface::Park->url('/money/invoices/'.$this->invoice->id)];
    }
}
