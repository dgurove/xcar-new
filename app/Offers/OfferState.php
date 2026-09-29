<?php

namespace App\Offers;

use App\Cars\HasLabels;

enum OfferState: string
{
    use HasLabels;

    case Draft = 'draft';
    case Gallery = 'gallery';       // «скоро в продаже»: без цены, принимаем интерес
    case Open = 'open';             // в каталоге; подтверждения принимаются, пока не прошёл bids_close_at
    case Garage = 'garage';         // отдана менеджеру в гараж: из продажи ушла, на витрину не вернётся
    case Sold = 'sold';             // идёт сделка
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Gallery => 'В галерее',
            self::Open => 'Приём подтверждений',
            self::Garage => 'В гараже',
            self::Sold => 'Идёт сделка',
            self::Delivered => 'Выдан',
            self::Cancelled => 'Снят',
            self::Archived => 'В архиве',
        };
    }

    /** Цвет чипа: open / urgent / closed / danger / plain. */
    public function tone(): string
    {
        return match ($this) {
            self::Open => 'open',
            self::Sold => 'urgent',
            self::Cancelled => 'danger',
            self::Draft, self::Gallery, self::Garage => 'plain',
            default => 'closed',
        };
    }

    public function isPublic(): bool
    {
        return $this === self::Open;
    }

    public function acceptsBids(): bool
    {
        return $this === self::Open;
    }

    public function acceptsInterest(): bool
    {
        return in_array($this, [self::Open, self::Gallery], true);
    }

    /**
     * Что сотрудник может сделать кнопкой из этого состояния — только уместное: «Снять с продажи» есть лишь у того,
     * что в продаже; у машины в сделке меню нет — её ведут подтверждения и маршрут. Сделка, выдача и гараж —
     * своими действиями, не отсюда.
     *
     * @return array<string, string> значение состояния => подпись кнопки
     */
    public function actions(): array
    {
        return match ($this) {
            self::Draft => [self::Open->value => 'Опубликовать', self::Gallery->value => 'В галерею «скоро»', self::Archived->value => 'В архив'],
            self::Gallery => [self::Open->value => 'Опубликовать', self::Draft->value => 'Снять с продажи', self::Archived->value => 'В архив'],
            self::Open => [self::Draft->value => 'Снять с продажи', self::Archived->value => 'В архив'],
            self::Delivered, self::Cancelled => [self::Archived->value => 'В архив'],
            self::Archived => [self::Draft->value => 'Вернуть в черновики'],
            self::Sold, self::Garage => [],
        };
    }

    public function allows(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Gallery, self::Open, self::Sold, self::Garage, self::Cancelled, self::Archived],
            self::Gallery => [self::Draft, self::Open, self::Sold, self::Garage, self::Cancelled, self::Archived],
            self::Open => [self::Draft, self::Sold, self::Garage, self::Cancelled, self::Archived],
            // Из гаража машина на витрину не возвращается: «отдали по ошибке» — обратно в черновик.
            self::Garage => [self::Draft, self::Archived],
            self::Sold => [self::Delivered, self::Cancelled, self::Open],
            self::Archived => [self::Draft],
            self::Delivered, self::Cancelled => [self::Archived],
        }, true);
    }
}
