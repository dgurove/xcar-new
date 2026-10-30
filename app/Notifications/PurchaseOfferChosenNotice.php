<?php

namespace App\Notifications;

use App\Offers\Bid;
use App\Support\Money;

final class PurchaseOfferChosenNotice extends Notice
{
    public function __construct(private Bid $offer) {}

    public function label(): string
    {
        return 'Цену в закупке выбрали';
    }

    public function title(): string
    {
        return 'Ваша цена '.Money::plain($this->offer->amount).' за '.$this->offer->purchaseCar->titleWithYear().' выбрана';
    }

    public function text(): ?string
    {
        return $this->offer->purchaseCar->purchase->publicTitle().', с вами свяжутся';
    }

    public function href(): string
    {
        return "/purchases/{$this->offer->purchaseCar->purchase->number}/{$this->offer->purchaseCar->ref}";
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
