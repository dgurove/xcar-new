<?php

namespace App\Notifications;

use App\Purchases\Purchase;
use App\Support\Ago;

final class PurchaseOpenedNotice extends Notice
{
    public function __construct(private Purchase $purchase) {}

    public function label(): string
    {
        return 'Открыта закупка';
    }

    public function title(): string
    {
        return 'Открыта '.mb_strtolower($this->purchase->publicTitle());
    }

    public function text(): ?string
    {
        return 'ТС: '.$this->purchase->cars()->count().($this->purchase->offers_close_at ? ', цены до '.Ago::day($this->purchase->offers_close_at, true) : '');
    }

    public function href(): string
    {
        return "/purchases/{$this->purchase->number}";
    }

    public function category(): string
    {
        return 'purchases';
    }
}
