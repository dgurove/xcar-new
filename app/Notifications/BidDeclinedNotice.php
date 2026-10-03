<?php

namespace App\Notifications;

use App\Offers\Bid;

final class BidDeclinedNotice extends Notice
{
    public function __construct(private Bid $bid) {}

    public function title(): string
    {
        $what = $this->bid->isGarage() ? 'в гараж' : number_format($this->bid->amount, 0, '', ' ').' ₽';

        return 'Подтверждение '.$what.' по № '.$this->bid->offer->number.' отклонено';
    }

    public function text(): ?string
    {
        return $this->bid->offer->titleWithYear();
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

    public function critical(): bool
    {
        return true;
    }
}
