<?php

namespace App\Notifications;

use App\Offers\Deal;

final class BidAcceptedNotice extends Notice
{
    public function __construct(private Deal $deal) {}

    public function title(): string
    {
        return 'Ваша ставка '.number_format($this->deal->amount, 0, '', ' ').' ₽ принята — № '.$this->deal->offer->number;
    }

    public function text(): ?string
    {
        return $this->deal->offer->titleWithYear().'. Сделка открыта.';
    }

    public function href(): string
    {
        return "/lk/sdelki/{$this->deal->id}";
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
    }
}
