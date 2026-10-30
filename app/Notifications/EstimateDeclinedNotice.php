<?php

namespace App\Notifications;

use App\Offers\Estimate;
use App\Support\Money;
use App\Support\Surface;
use App\Users\User;

/** Менеджер отказался от оценки (11.10.2026) — админам тихой строкой: кто, сколько хочет; ведёт в окошко оценок. */
final class EstimateDeclinedNotice extends Notice
{
    public function __construct(private Estimate $estimate) {}

    public function label(): string
    {
        return 'Отказ от оценки';
    }

    public function title(): string
    {
        return $this->estimate->user->shortName().': отказ от оценки, хочет '.Money::rub($this->estimate->wish_price);
    }

    public function text(): ?string
    {
        return $this->estimate->offer->titleWithYear();
    }

    public function href(): string
    {
        return Surface::Crm->url('/?'.http_build_query(['vid' => 'table', 'preset' => $this->estimate->isCorrection() ? 'priced' : 'unpriced', 'peek' => $this->estimate->offer->number]));
    }

    public function category(): string
    {
        return 'bids';
    }

    public function quiet(): bool
    {
        return true;
    }

    public function actor(): ?User
    {
        return $this->estimate->user;
    }
}
