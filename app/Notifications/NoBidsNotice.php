<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Ago;

/**
 * Ни одного подтверждения (22.10.2026, владелец: «по горящему ни одного подтверждения — потеряли деньги, в идеале ни одно
 * предложение не должно остаться без подтверждений»): за 3 часа до конца приёма — тем, кто машину открывал, а
 * подтверждения нет ни у кого (`NoBidsAlarm`); «Уведомить менеджеров» в CRM — всем, кому она открыта. Громко: висит до
 * нажатия, Telegram и подписчику бота предложений.
 */
final class NoBidsNotice extends Notice
{
    use OffersPack;

    public function __construct(private Offer $offer) {}

    public function label(): string
    {
        return 'Ни одного подтверждения';
    }

    public function title(): string
    {
        return 'Ни одного подтверждения: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        $at = $this->offer->bids_close_at;

        return $at ? 'Приём до '.($at->isToday() ? $at->format('H:i') : Ago::day($at, true)) : null;
    }

    public function href(): string
    {
        return "/offers/{$this->offer->number}";
    }

    public function subject(): string
    {
        return "/offers/{$this->offer->number}";
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function icon(): string
    {
        return 'fire';
    }

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
        return self::offerSubject($this->offer->id);
    }

    protected function visibleOffer(): ?Offer
    {
        return $this->offer;
    }
}
