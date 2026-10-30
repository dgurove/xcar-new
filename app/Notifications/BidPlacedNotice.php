<?php

namespace App\Notifications;

use App\Offers\Bid;
use App\Support\Money;
use App\Support\Surface;
use App\Users\User;

final class BidPlacedNotice extends Notice
{
    public function __construct(private Bid $bid) {}

    public function label(): string
    {
        return 'Новое подтверждение';
    }

    public function title(): string
    {
        if ($this->bid->isGarage()) {
            return 'В гараж: № '.$this->bid->offer->number;
        }

        return 'Подтверждение '.Money::plain($this->bid->amount).' по № '.$this->bid->offer->number;
    }

    public function text(): ?string
    {
        return $this->bid->user->name.' — '.$this->bid->offer->titleWithYear();
    }

    public function href(): string
    {
        // Сотрудникам: в CRM, в редактор — на сайте тот же путь вёл на витрину.
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
