<?php

namespace App\Notifications;

use App\Purchases\Offer;

final class PurchaseOfferChosenNotice extends Notice
{
    public function __construct(private Offer $offer) {}

    public function title(): string
    {
        return 'Ваша цена '.number_format($this->offer->amount, 0, '', ' ').' ₽ за '.$this->offer->car->titleWithYear().' выбрана';
    }

    public function text(): ?string
    {
        return $this->offer->car->purchase->publicTitle().' · с вами свяжутся';
    }

    public function href(): string
    {
        return "/zakupki/{$this->offer->car->purchase->number}/{$this->offer->car->ref}";
    }
}
