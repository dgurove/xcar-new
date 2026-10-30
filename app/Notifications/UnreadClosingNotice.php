<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Plural;
use Carbon\CarbonInterface;

/**
 * За 3 часа до конца приёма — о машинах в «Избранном» и тех, что человек так и не открыл (`RemindUnreadClosing`,
 * 21.10.2026, владелец: «проёбываемся по срокам»): «Приём до 17:00: 2 в избранном», ниже «Ещё 4 вы не открывали» и марки;
 * без избранного — «Приём до 17:00: 6 непросмотренных». Одна — её название, ведёт на неё; пачка непросмотренных — в
 * «Горящие», где фильтр «Не просмотренные» оставляет ровно их; с избранным — «Горящие» целиком или «Избранное». Каналы — как
 * у «Опубликовано N» (`OffersPack`).
 */
final class UnreadClosingNotice extends Notice
{
    use OffersPack;

    /** @param  list<string>  $brands  марки без повторов, частые первыми */
    public function __construct(private int $unread, private int $favorites, private array $brands, private CarbonInterface $closeAt, private ?Offer $offer = null) {}

    public function label(): string
    {
        return '3 часа до конца приёма';
    }

    public function title(): string
    {
        $until = $this->closeAt->isToday() ? $this->closeAt->format('H:i') : $this->closeAt->translatedFormat('j M, H:i');

        return 'Приём до '.$until.': '.match (true) {
            $this->offer !== null => $this->offer->titleWithYear(),
            $this->favorites > 0 => $this->favorites.' в избранном',
            default => $this->unread.' '.Plural::of($this->unread, ['непросмотренное', 'непросмотренных', 'непросмотренных']),
        };
    }

    public function text(): ?string
    {
        if ($this->offer) {
            return $this->favorites ? 'В избранном, подтверждения нет' : 'Вы его ещё не открывали';
        }
        $brands = NewOffersNotice::brandsLine($this->brands);

        return $this->favorites && $this->unread ? 'Ещё '.$this->unread.' вы не открывали'.($brands ? ': '.$brands : '') : $brands;
    }

    public function href(): string
    {
        return match (true) {
            $this->offer !== null => "/offers/{$this->offer->number}",
            $this->favorites > 0 && $this->unread === 0 => '/account/favorites',
            // Избранное уже открывали — «Не просмотренные» его спрятали бы.
            $this->favorites > 0 => '/offers?view=ending&unread=0',
            default => '/offers?view=ending',
        };
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
