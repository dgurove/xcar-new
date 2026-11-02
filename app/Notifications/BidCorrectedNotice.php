<?php

namespace App\Notifications;

use App\Offers\Bid;
use App\Support\Money;
use App\Users\User;

/** Менеджеру: админ поправил опечатку в цене его подтверждения (02.11.2026, `CorrectBidAmount`). */
final class BidCorrectedNotice extends Notice
{
    public function __construct(private Bid $bid, private int $was, private User $by) {}

    public function label(): string
    {
        return 'Цена подтверждения поправлена';
    }

    public function title(): string
    {
        return 'Ваше подтверждение: '.Money::plain($this->bid->amount).' вместо '.Money::plain($this->was);
    }

    public function text(): ?string
    {
        return $this->bid->offer->titleWithYear().', № '.$this->bid->offer->number.' — поправил '.$this->by->shortName();
    }

    public function href(): string
    {
        return '/offers/'.$this->bid->offer->number;
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
        return true;
    }
}
