<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Money;

/**
 * Менеджеру: машину в сделке опубликовали для других менеджеров и его волна наступила (`PublishDeal`, `NotifyViewers`). Ему
 * это новое предложение, о сделке ни слова (05.11.2026, владелец: «визуально нигде ничем не отличается от других»);
 * подтверждение встанет резервом.
 */
final class DealOpenedNotice extends Notice
{
    /** Был до 12.11.2026 («показ всем» / белый список); оставлен, чтобы уведомления из очереди на момент выкладки читались. */
    private bool $plain = false;

    public function __construct(private Offer $offer) {}

    public function label(): string
    {
        return 'Новое предложение (сделка для других)';
    }

    public function title(): string
    {
        return 'Новое предложение: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->offer->asking_price ? Money::plain($this->offer->asking_price) : null;
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

    public function telegramSubject(): ?string
    {
        return self::offerSubject($this->offer->id);
    }

    protected function visibleOffer(): ?Offer
    {
        return $this->offer;
    }
}
