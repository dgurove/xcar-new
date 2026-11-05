<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Money;

/**
 * Менеджеру: предложение в сделке открыли ему — белым списком «Кому показывать в сделке» или показом всем, пока менеджер
 * сделки думает о закупочной (`plain`, `ShowDealToAll`, 04.11.2026). Ему это новое предложение, о сделке ни слова (05.11.2026,
 * владелец: «визуально нигде ничем не отличается от других»); подтверждение встанет резервом. `plain` различает только
 * подпись вида в «Доставке» CRM.
 */
final class DealOpenedNotice extends Notice
{
    public function __construct(private Offer $offer, private bool $plain = false) {}

    public function label(): string
    {
        return $this->plain ? 'Новое предложение (показ всем)' : 'Новое предложение (белый список сделки)';
    }

    public function title(): string
    {
        return 'Новое предложение: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->offer->asking_price ? Money::plain($this->offer->asking_price) : null;
    }

    public function href(): string
    {
        return "/offers/{$this->offer->number}";
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function category(): string
    {
        return 'offers';
    }

    public function telegramSubject(): ?string
    {
        return self::offerSubject($this->offer->id);
    }

    protected function visibleOffer(): ?Offer
    {
        return $this->offer;
    }
}
