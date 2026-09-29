<?php

namespace App\Telegram\Messages;

use App\Offers\BidState;
use App\Offers\Offer;
use App\Support\Money;
use App\Support\Surface;

/** Приём подтверждений закрылся по сроку: сколько подтверждений и лучшая цена — выбирать нам. */
final class BidsClosed extends Message
{
    public function __construct(private Offer $offer) {}

    protected function title(): string
    {
        return 'Приём закрыт';
    }

    protected function lines(): array
    {
        $bids = $this->offer->bids()->where('state', BidState::Active)->get();

        return [
            $this->offer->titleWithYear().', №'.$this->offer->number,
            $bids->isEmpty() ? 'Подтверждений нет' : 'Подтверждений: '.$bids->count().', лучшая '.Money::rub($bids->max('amount')),
        ];
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'Предложение в CRM', 'url' => Surface::Crm->url('/offers/'.$this->offer->number)];
    }
}
