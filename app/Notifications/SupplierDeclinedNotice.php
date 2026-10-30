<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Telegram\Text;

/**
 * Менеджеру сделки: поставщик отказал продавать (28.10.2026, `DeclineBySupplier`). Громко — иначе он поедет забирать.
 * Тема своя (`/deals/{id}/declined`): отмена сделки стирает её прежние уведомления (`RetractDealNotices`), а это — нет.
 */
final class SupplierDeclinedNotice extends Notice
{
    public function __construct(private Deal $deal) {}

    public function label(): string
    {
        return 'Поставщик отказал';
    }

    public function title(): string
    {
        return 'Поставщик отказал в продаже: '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->deal->close_note ? 'Сделка закрыта. '.$this->deal->close_note : 'Сделка закрыта';
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

    public function subject(): string
    {
        return $this->deal->href().'/declined';
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => Text::lines($this->deal->offer, $this->text()), 'button' => 'Открыть сделку'];
    }
}
