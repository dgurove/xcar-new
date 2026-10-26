<?php

namespace App\Notifications;

use App\Offers\Bid;
use App\Support\Money;
use App\Support\Surface;
use App\Users\User;

/** Админам: менеджер ответил на контрпредложение — согласился (сделка уже открыта) или отказался. */
final class CounterAnsweredNotice extends Notice
{
    public function __construct(private Bid $bid, private bool $agreed) {}

    public function title(): string
    {
        return ($this->agreed ? 'Согласен на ' : 'Отказ от ').Money::plain($this->bid->counter_amount).': № '.$this->bid->offer->number;
    }

    public function text(): ?string
    {
        return $this->bid->user->name.' — '.$this->bid->offer->titleWithYear().($this->agreed ? ', сделка открыта' : '');
    }

    public function href(): string
    {
        return Surface::Crm->url("/offers/{$this->bid->offer->number}");
    }

    public function offerNumber(): ?int
    {
        return $this->bid->offer->number;
    }

    public function category(): string
    {
        return 'bids';
    }

    public function important(): bool
    {
        return $this->agreed;
    }

    public function actor(): ?User
    {
        return $this->bid->user;
    }
}
