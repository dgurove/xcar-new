<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Offers\PriceView;
use App\Users\User;

/**
 * Срочное предложение (12.10.2026, владелец: «всем менеджерам и покупателям уведомление при публикации»): громко —
 * мимо настроек категорий (`critical`), в Telegram всем привязанным, карточка со звуком висит до нажатия. Цена — как её
 * видит адресат (`PriceView`: менеджеру «заявленная → продажи», покупателю — продажи), и срок приёма.
 */
final class UrgentOfferNotice extends Notice
{
    public function __construct(private Offer $offer, private ?User $for = null) {}

    public function label(): string
    {
        return 'Срочное предложение';
    }

    public function title(): string
    {
        return 'Срочно: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        $price = PriceView::for($this->offer, $this->for);
        $money = $price->shown() ? ($price->withFrom() ? PriceView::money($price->from).' → ' : '').PriceView::money($price->to).' ₽' : null;

        return collect([$money, $this->offer->bids_close_at ? 'приём до '.$this->offer->bids_close_at->translatedFormat('j M, H:i') : null])
            ->filter()->implode(', ') ?: null;
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

    public function critical(): bool
    {
        return true;
    }

    public function important(): bool
    {
        return true;
    }

    public function icon(): string
    {
        return 'fire';
    }

    public function toTelegram(): ?array
    {
        $bids = $this->for?->bidsOnSite() ?? false;

        return ['title' => '🔥 '.$this->title(), 'lines' => array_values(array_filter([$this->text()])), 'button' => $bids ? 'Подтвердить' : 'Посмотреть'];
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
