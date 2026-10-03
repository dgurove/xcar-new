<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Support\Money;
use App\Telegram\Text;

final class BidAcceptedNotice extends Notice
{
    public function __construct(private Deal $deal) {}

    public function title(): string
    {
        if ($this->deal->isGarage()) {
            return 'Машина ваша, в гараж — № '.$this->deal->offer->number;
        }

        return 'Ваше подтверждение '.number_format($this->deal->amount, 0, '', ' ').' ₽ принято — № '.$this->deal->offer->number;
    }

    public function text(): ?string
    {
        return $this->deal->offer->titleWithYear().($this->deal->isGarage() ? '. Ждём страховую' : '. Сделка открыта');
    }

    public function href(): string
    {
        return $this->deal->href();
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

        if ($this->deal->isGarage()) {
            return ['title' => 'В гараж: '.$offer->titleWithYear(), 'lines' => Text::lines($offer, 'Ждём страховую'), 'button' => 'Открыть в гараже'];
        }

        return ['title' => 'Подтверждение '.$offer->titleWithYear().' принято', 'lines' => Text::lines($offer, Money::rub($this->deal->amount)), 'button' => 'Открыть сделку'];
    }
}
