<?php

namespace App\Notifications;

use App\Offers\Deal;

final class BidAcceptedNotice extends Notice
{
    public function __construct(private Deal $deal) {}

    public function title(): string
    {
        return 'Ваше подтверждение '.number_format($this->deal->amount, 0, '', ' ').' ₽ принято — № '.$this->deal->offer->number;
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

    public function category(): string
    {
        return 'bids';
    }

    public function critical(): bool
    {
        return true;
    }
}
