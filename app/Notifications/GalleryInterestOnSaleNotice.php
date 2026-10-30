<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Money;

/**
 * Тому, кто в галерее проявил интерес к машине: она вышла в продажу (12.10.2026, владелец). Идёт вместо «Нового
 * предложения», не вдобавок, — когда его волна наступила (`NotifyViewers`); покупателю менеджера — когда наступила волна
 * менеджера и машина ему видна.
 */
final class GalleryInterestOnSaleNotice extends Notice
{
    /** buyer — покупателю менеджера: он не подтверждает ценой, срок приёма ему ни к чему. */
    public function __construct(private Offer $offer, private bool $buyer = false) {}

    public function label(): string
    {
        return 'В продаже, отмечали интерес';
    }

    public function title(): string
    {
        return 'В продаже, вы отмечали интерес: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return collect([
            $this->offer->asking_price ? Money::rub($this->offer->asking_price) : null,
            ! $this->buyer && $this->offer->bids_close_at ? 'приём подтверждений до '.$this->offer->bids_close_at->translatedFormat('j M, H:i') : null,
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

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => array_values(array_filter([$this->text()])), 'button' => 'Посмотреть'];
    }

    public function telegramSubject(): ?string
    {
        return self::offerSubject($this->offer->id);
    }

    protected function visibleOffer(): ?Offer
    {
        return $this->offer;
    }
}
