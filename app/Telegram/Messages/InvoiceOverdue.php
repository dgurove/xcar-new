<?php

namespace App\Telegram\Messages;

use App\Billing\Invoice;
use App\Billing\Seller;
use App\Garage\Car;
use App\Offers\Offer;
use App\Support\Money;
use App\Support\Surface;
use App\Telegram\Text;

/** Счёт просрочен: контрагент, остаток, дней; «Оплачен» закрывает остаток одним нажатием. */
final class InvoiceOverdue extends Message
{
    public function __construct(private Invoice $invoice) {}

    protected function title(): string
    {
        // Счёт по сделке или гаражу — машиной, как остальные сообщения; счёт парковки — прежним видом.
        if ($offer = $this->offer()) {
            return ($this->invoice->isOwed() ? 'Мы просрочили выплату по ' : 'Счёт по ').$offer->titleWithYear().($this->invoice->isOwed() ? '' : ' просрочен');
        }

        return ($this->invoice->isOwed() ? 'Мы просрочили ' : 'Счёт просрочен ').$this->invoice->label();
    }

    protected function lines(): array
    {
        $i = $this->invoice;

        $rest = 'Остаток '.Money::rub($i->remaining()).' из '.Money::rub($i->total).', срок '.$i->due_at->translatedFormat('j M').', '.$i->overdueDays().' дн назад';
        if ($offer = $this->offer()) {
            return Text::lines($offer, $rest, $i->label().', '.$i->party->name);
        }

        return [
            $i->party->name,
            'Остаток '.Money::rub($i->remaining()).' из '.Money::rub($i->total),
            'Срок '.$i->due_at->translatedFormat('j M').', '.$i->overdueDays().' дн назад',
            $i->vehicle?->titleWithYear(),
        ];
    }

    private function offer(): ?Offer
    {
        return $this->invoice->deal?->offer ?? Car::ofInvoice($this->invoice)?->offer;
    }

    protected function decisions(): array
    {
        return [['text' => $this->invoice->isOwed() ? 'Перечислено' : 'Оплачен', 'callback_data' => 'invoice:'.$this->invoice->id.':paid']];
    }

    protected function link(): array
    {
        return $this->invoice->seller === Seller::Park
            ? ['text' => 'Счёт на парковке', 'url' => Surface::Park->url('/money/invoices/'.$this->invoice->id)]
            : ['text' => 'Счёт в CRM', 'url' => Surface::Crm->url('/work/money/invoices/'.$this->invoice->id)];
    }
}
