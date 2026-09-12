<?php

namespace App\Notifications;

use App\Offers\Bid;

final class BidDeclinedNotice extends Notice
{
    public function __construct(private Bid $bid) {}

    public function title(): string
    {
        return 'Подтверждение '.number_format($this->bid->amount, 0, '', ' ').' ₽ по № '.$this->bid->offer->number.' отклонено';
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
}
