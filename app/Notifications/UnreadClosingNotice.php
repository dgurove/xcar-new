<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Ago;
use App\Support\Plural;
use Carbon\CarbonInterface;

/**
 * За 3 часа до конца приёма — о машинах в «Избранном», тех, что человек так и не открыл, и тех, что открывал, а подтверждения
 * нет ни у кого (`RemindUnreadClosing`, 21.10.2026, владелец: «проёбываемся по срокам»; без подтверждений — с 07.11.2026, было
 * отдельным `NoBidsNotice` на каждую машину): «Приём до 17:00: 2 в избранном», ниже «3 без подтверждений, 4 вы не открывали» и
 * марки; без избранного — «Приём до 17:00: 3 без подтверждений» или «6 непросмотренных». Одна — её название, ведёт на неё;
 * пачка непросмотренных — в «Горящие», где фильтр «Не просмотренные» оставляет ровно их; иначе — «Горящие» целиком или
 * «Избранное». Каналы — как у «Опубликовано N» (`OffersPack`); есть без подтверждений — громко и подписчику бота предложений,
 * как было у `NoBidsNotice`.
 */
final class UnreadClosingNotice extends Notice
{
    use OffersPack;

    /** @param  list<string>  $brands  марки без повторов, частые первыми */
    public function __construct(private int $unread, private int $favorites, private array $brands, private CarbonInterface $closeAt, private ?Offer $offer = null,
        private int $noBids = 0) {}

    public function label(): string
    {
        return '3 часа до конца приёма';
    }

    public function title(): string
    {
        $until = $this->closeAt->isToday() ? $this->closeAt->format('H:i') : Ago::day($this->closeAt, true);

        return 'Приём до '.$until.': '.match (true) {
            $this->offer !== null => $this->offer->titleWithYear(),
            $this->favorites > 0 => $this->favorites.' в избранном',
            $this->noBids > 0 => $this->noBids.' без подтверждений',
            default => $this->unread.' '.Plural::of($this->unread, ['непросмотренное', 'непросмотренных', 'непросмотренных']),
        };
    }

    public function text(): ?string
    {
        if ($this->offer) {
            return match (true) {
                $this->favorites > 0 => 'В избранном, подтверждения нет',
                $this->noBids > 0 => 'Ни одного подтверждения',
                default => 'Вы его ещё не открывали',
            };
        }
        $brands = NewOffersNotice::brandsLine($this->brands);
        // В заголовке — первое из избранного, без подтверждений, непросмотренных; остальное — строкой.
        $rest = array_filter([
            $this->favorites > 0 && $this->noBids > 0 ? $this->noBids.' без подтверждений' : null,
            ($this->favorites > 0 || $this->noBids > 0) && $this->unread > 0 ? $this->unread.' вы не открывали' : null,
        ]);

        return $rest ? 'Ещё '.implode(', ', $rest).($brands ? ': '.$brands : '') : $brands;
    }

    /** Есть машины, где подтверждения нет ни у кого, — громко: потеряем деньги (22.10.2026). */
    public function important(): bool
    {
        return $this->noBids > 0;
    }

    protected function skipsOffersBot(): bool
    {
        return $this->noBids === 0;
    }

    public function href(): string
    {
        return match (true) {
            $this->offer !== null => "/offers/{$this->offer->number}",
            $this->favorites > 0 && $this->unread === 0 && $this->noBids === 0 => '/account/favorites',
            $this->noBids === 0 && $this->favorites === 0 => '/offers?view=ending',
            // Избранное и без подтверждений уже открывали — «Не просмотренные» их спрятали бы.
            default => '/offers?view=ending&unread=0',
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
