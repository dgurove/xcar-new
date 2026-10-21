<?php

namespace App\Notifications;

use App\Offers\Offer;

/**
 * За 30 минут до конца приёма — машины «Избранного» без своего подтверждения (`RemindUnreadClosing::last`, 21.10.2026,
 * как «скоро закончится» у eBay): «Через 30 минут закончится приём: Haval F7, 2023» или «…: 2 из избранного». Громко —
 * карточка со звуком висит до нажатия; Telegram и подписчику бота предложений: бот об избранном не пишет.
 */
final class FavoriteClosingNotice extends Notice
{
    use OffersPack;

    /** @param  list<string>  $brands  марки без повторов, частые первыми */
    public function __construct(private int $count, private array $brands, private ?Offer $offer = null) {}

    public function title(): string
    {
        return 'Через 30 минут закончится приём: '.($this->offer ? $this->offer->titleWithYear() : $this->count.' из избранного');
    }

    public function text(): ?string
    {
        return $this->offer ? 'В избранном, подтверждения нет' : NewOffersNotice::brandsLine($this->brands);
    }

    public function href(): string
    {
        return $this->offer ? "/offers/{$this->offer->number}" : '/offers?view=favorite';
    }

    public function subject(): string
    {
        return '/offers?view=favorite';
    }

    public function offerNumber(): ?int
    {
        return $this->offer?->number;
    }

    public function icon(): string
    {
        return 'fire';
    }

    /** Полчаса — висит до нажатия. */
    public function important(): bool
    {
        return true;
    }

    protected function skipsOffersBot(): bool
    {
        return false;
    }

    public function telegramSubject(): ?string
    {
        return $this->offer ? self::offerSubject($this->offer->id) : null;
    }

    protected function visibleOffer(): ?Offer
    {
        return $this->offer;
    }
}
