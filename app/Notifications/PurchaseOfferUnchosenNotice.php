<?php

namespace App\Notifications;

use App\Offers\Bid;
use App\Support\Money;

final class PurchaseOfferUnchosenNotice extends Notice
{
    public function __construct(private Bid $offer) {}

    public function label(): string
    {
        return 'Выбор цены в закупке снят';
    }

    public function title(): string
    {
        return 'Выбор цены '.Money::plain($this->offer->amount).' за '.$this->offer->purchaseCar->titleWithYear().' отменён';
    }

    public function text(): ?string
    {
        return $this->offer->purchaseCar->purchase->publicTitle().', цена снова на рассмотрении';
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
