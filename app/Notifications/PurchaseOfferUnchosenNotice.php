<?php

namespace App\Notifications;

use App\Purchases\Offer;
use App\Support\Money;

final class PurchaseOfferUnchosenNotice extends Notice
{
    public function __construct(private Offer $offer) {}

    public function title(): string
    {
        return 'Выбор цены '.Money::plain($this->offer->amount).' за '.$this->offer->car->titleWithYear().' отменён';
    }

    public function text(): ?string
    {
        return $this->offer->car->purchase->publicTitle().', цена снова на рассмотрении';
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
