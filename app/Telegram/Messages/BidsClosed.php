<?php

namespace App\Telegram\Messages;

use App\Offers\BidKind;
use App\Offers\BidState;
use App\Offers\Offer;
use App\Support\Money;
use App\Support\Surface;
use App\Telegram\Text;

/** Приём подтверждений закрылся по сроку: сколько подтверждений и лучшая цена — выбирать нам. */
final class BidsClosed extends Message
{
    public function __construct(private Offer $offer) {}

    protected function title(): string
    {
        return 'Приём закрыт: '.$this->offer->titleWithYear();
    }

    protected function lines(): array
    {
        $bids = $this->offer->bids()->where('state', BidState::Active)->get();
        // «Лучшая» — среди цен покупателю: гаражные без цены считаются отдельно.
        [$garage, $priced] = $bids->partition(fn ($b) => $b->kind === BidKind::Garage);
        $summary = match (true) {
            $bids->isEmpty() => 'Подтверждений нет',
            $priced->isEmpty() => 'В гараж '.$garage->count(),
            default => 'Подтверждений '.$priced->count().', лучшая '.Money::rub($priced->max('amount')).($garage->isNotEmpty() ? ', в гараж '.$garage->count() : ''),
        };

        return Text::lines($this->offer, $summary);
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
