<?php

namespace App\Notifications;

use App\Billing\Invoice;
use App\Offers\Deal;
use App\Telegram\Text;

/**
 * Менеджеру: напоминание про отправленный ему счёт (19.11.2026, «Напомнить менеджеру» в CRM, `Billing\Actions\NudgeInvoice`):
 * что отправили, когда, сколько дней прошло и что сделать. Важное — лента, пуш и Telegram.
 */
final class InvoiceNudgeNotice extends Notice
{
    public function __construct(private Deal $deal, private Invoice $invoice, private string $line) {}

    public function label(): string
    {
        return 'Напоминание об оплате';
    }

    public function title(): string
    {
        return 'Ждём оплату: '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->line;
    }

    public function href(): string
    {
        return $this->deal->href();
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function critical(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => Text::lines($this->deal->offer, $this->line), 'button' => 'Открыть сделку'];
    }
}
