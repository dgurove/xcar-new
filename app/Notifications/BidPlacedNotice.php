<?php

namespace App\Notifications;

use App\Offers\Bid;
use App\Users\User;

final class BidPlacedNotice extends Notice
{
    public function __construct(private Bid $bid) {}

    public function title(): string
    {
        if ($this->bid->isGarage()) {
            return 'В гараж: № '.$this->bid->offer->number;
        }

        return 'Подтверждение '.number_format($this->bid->amount, 0, '', ' ').' ₽ по № '.$this->bid->offer->number;
    }

    public function text(): ?string
    {
        return $this->bid->user->name.' — '.$this->bid->offer->titleWithYear();
    }

    public function href(): string
    {
        return "/offers/{$this->bid->offer->number}";
    }

    public function offerNumber(): ?int
    {
        return $this->bid->offer->number;
    }

    public function category(): string
    {
        return 'bids';
    }

    /** Новое подтверждение ждёт решения админа — важное. */
    public function important(): bool
    {
        return true;
    }

    public function actor(): ?User
    {
        return $this->bid->user;
    }
}
