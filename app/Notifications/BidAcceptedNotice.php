<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Support\Money;

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
        return "/deals/{$this->deal->id}";
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

    public function toTelegram(): ?array
    {
        $offer = $this->deal->offer;

        return ['title' => 'Подтверждение '.Money::rub($this->deal->amount).' принято', 'lines' => [$offer->titleWithYear(), '№ '.$offer->number], 'button' => 'Открыть сделку'];
    }
}
