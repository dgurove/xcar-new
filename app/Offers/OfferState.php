<?php

namespace App\Offers;

use App\Cars\HasLabels;

enum OfferState: string
{
    use HasLabels;

    case Draft = 'draft';
    case Gallery = 'gallery';       // «скоро в продаже»: без цены, принимаем интерес
    case Open = 'open';             // в каталоге; подтверждения принимаются, пока не прошёл bids_close_at
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
            self::Draft, self::Gallery => 'plain',
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

    public function allows(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Gallery, self::Open, self::Sold, self::Cancelled, self::Archived],
            self::Gallery => [self::Draft, self::Open, self::Sold, self::Cancelled, self::Archived],
            self::Open => [self::Draft, self::Sold, self::Cancelled, self::Archived],
            self::Sold => [self::Delivered, self::Cancelled, self::Open],
            self::Archived => [self::Draft],
            self::Delivered, self::Cancelled => [self::Archived],
        }, true);
    }
}
