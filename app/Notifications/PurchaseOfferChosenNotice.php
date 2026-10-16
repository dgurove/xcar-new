<?php

namespace App\Notifications;

use App\Purchases\Offer;
use App\Support\Money;

final class PurchaseOfferChosenNotice extends Notice
{
    public function __construct(private Offer $offer) {}

    public function title(): string
    {
        return 'Ваша цена '.Money::plain($this->offer->amount).' за '.$this->offer->car->titleWithYear().' выбрана';
    }

    public function text(): ?string
    {
        return $this->offer->car->purchase->publicTitle().', с вами свяжутся';
    }

    public function href(): string
    {
        return "/purchases/{$this->offer->car->purchase->number}/{$this->offer->car->ref}";
    }

    public function category(): string
    {
        return 'purchases';
    }

    public function critical(): bool
    {
        return true;
    }
}
