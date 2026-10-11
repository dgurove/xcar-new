<?php

namespace App\Notifications;

use App\Offers\Estimate;
use App\Support\Money;
use App\Support\Surface;
use App\Users\User;

/** Менеджер оценил — админам карточка со звуком и аватаром; ведёт в окошко «Оценить» этой строки. */
final class EstimateGivenNotice extends Notice
{
    public function __construct(private Estimate $estimate) {}

    public function title(): string
    {
        return $this->estimate->user->shortName().': оценка '.Money::rub($this->estimate->amount);
    }

    public function text(): ?string
    {
        return $this->estimate->offer->titleWithYear();
    }

    public function href(): string
    {
        return Surface::Crm->url('/?'.http_build_query(['preset' => 'unpriced', 'vid' => 'table', 'peek' => $this->estimate->offer->number]));
    }

    public function category(): string
    {
        return 'bids';
    }

    public function important(): bool
    {
        return true;
    }

    public function actor(): ?User
    {
        return $this->estimate->user;
    }
}
