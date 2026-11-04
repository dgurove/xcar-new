<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Money;

/**
 * Менеджеру: предложение в сделке открыли ему — видно в предложениях с меткой
 * «Идёт сделка», подтверждение встанет резервом. `plain` — показ всем, пока менеджер сделки думает о закупочной
 * (`ShowDealToAll`, 04.11.2026): для них это новое предложение, о сделке ни слова.
 */
final class DealOpenedNotice extends Notice
{
    public function __construct(private Offer $offer, private bool $plain = false) {}

    public function label(): string
    {
        return $this->plain ? 'Новое предложение' : 'Предложение в сделке открыто';
    }

    public function title(): string
    {
        return ($this->plain ? 'Новое предложение: ' : 'Предложение в сделке открыто вам: ').$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        $price = $this->offer->asking_price ? Money::plain($this->offer->asking_price) : '';
        if ($this->plain) {
            return $price !== '' ? $price : null;
        }

        return ($price !== '' ? $price.', ' : '').'идёт сделка, подтверждение встанет резервом';
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
