<?php

namespace App\Notifications;

use App\Purchases\Purchase;

final class PurchaseOpenedNotice extends Notice
{
    public function __construct(private Purchase $purchase) {}

    public function title(): string
    {
        return 'Открыта '.mb_strtolower($this->purchase->publicTitle());
    }

    public function text(): ?string
    {
        return 'Машин: '.$this->purchase->cars()->count().($this->purchase->offers_close_at ? ', цены до '.$this->purchase->offers_close_at->translatedFormat('j M, H:i') : '');
    }

    public function href(): string
    {
        return "/zakupki/{$this->purchase->number}";
    }
}
