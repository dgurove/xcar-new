<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Plural;
use Carbon\CarbonInterface;

/**
 * За 3 часа до конца приёма — о машинах, которые человек так и не открыл (`RemindUnreadClosing`, 21.10.2026, владелец:
 * «проёбываемся по срокам»): «Приём до 17:00: 6 непросмотренных», ниже марки; одна — её название, ведёт на неё; пачка — в
 * «Горящие», где фильтр «Не просмотренные» оставляет ровно их. Каналы — как у «Опубликовано N» (`OffersPack`).
 */
final class UnreadClosingNotice extends Notice
{
    use OffersPack;

    /** @param  list<string>  $brands  марки без повторов, частые первыми */
    public function __construct(private int $count, private array $brands, private CarbonInterface $closeAt, private ?Offer $offer = null) {}

    public function title(): string
    {
        $until = $this->closeAt->isToday() ? $this->closeAt->format('H:i') : $this->closeAt->translatedFormat('j M, H:i');

        return 'Приём до '.$until.': '.($this->offer
            ? $this->offer->titleWithYear()
            : $this->count.' '.Plural::of($this->count, ['непросмотренное', 'непросмотренных', 'непросмотренных']));
    }

    public function text(): ?string
    {
        return $this->offer ? 'Вы его ещё не открывали' : NewOffersNotice::brandsLine($this->brands);
    }

    public function href(): string
    {
        return $this->offer ? "/offers/{$this->offer->number}" : '/offers?view=ending';
    }

    /** Своя тема: пуш о сроке не заменяет пуш «Опубликовано N». */
    public function subject(): string
    {
        return '/offers?view=ending';
    }

    public function offerNumber(): ?int
    {
        return $this->offer?->number;
    }

    public function icon(): string
    {
        return 'fire';
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
