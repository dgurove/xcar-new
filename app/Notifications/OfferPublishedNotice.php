<?php

namespace App\Notifications;

use App\Offers\Offer;

final class OfferPublishedNotice extends Notice
{
    public function __construct(private Offer $offer) {}

    public function title(): string
    {
        return 'Новое предложение: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->offer->asking_price ? number_format($this->offer->asking_price, 0, '', ' ').' ₽ · приём ставок до '.$this->offer->bids_close_at?->translatedFormat('j M, H:i') : null;
    }

    public function href(): string
    {
        return "/offers/{$this->offer->number}";
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }
}
