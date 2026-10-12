<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Money;

/**
 * Тому, кто в галерее проявил интерес к машине: она вышла в продажу (12.10.2026, владелец). Идёт вместо «Нового
 * предложения», не вдобавок, — когда его волна наступила (`NotifyViewers`).
 */
final class GalleryInterestOnSaleNotice extends Notice
{
    public function __construct(private Offer $offer) {}

    public function title(): string
    {
        return 'В продаже, вы отмечали интерес: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return collect([
            $this->offer->asking_price ? Money::rub($this->offer->asking_price) : null,
            $this->offer->bids_close_at ? 'приём подтверждений до '.$this->offer->bids_close_at->translatedFormat('j M, H:i') : null,
        ])->filter()->implode(', ') ?: null;
    }

    public function href(): string
    {
        return "/offers/{$this->offer->number}";
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function category(): string
    {
        return 'offers';
    }

    public function important(): bool
    {
        return true;
    }
}
