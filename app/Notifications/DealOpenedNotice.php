<?php

namespace App\Notifications;

use App\Offers\Offer;

/**
 * Менеджеру: предложение в сделке открыли ему — видно в предложениях с меткой
 * «Идёт сделка», подтверждение встанет резервом.
 */
final class DealOpenedNotice extends Notice
{
    public function __construct(private Offer $offer) {}

    public function title(): string
    {
        return 'Предложение в сделке открыто вам: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        $price = $this->offer->asking_price ? number_format($this->offer->asking_price, 0, '', ' ').' ₽, ' : '';

        return $price.'идёт сделка, подтверждение встанет резервом';
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
