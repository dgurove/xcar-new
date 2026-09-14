<?php

namespace App\Purchases;

use App\Cars\HasLabels;

/** Состояний три; «приём закрыт» — не состояние, а прошедший срок `offers_close_at` (Purchase::closed). */
enum PurchaseState: string
{
    use HasLabels;

    case Draft = 'draft';
    case Open = 'open';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Open => 'Приём цен',
            self::Archived => 'В архиве',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'open',
            self::Draft => 'plain',
            default => 'closed',
        };
    }

    /** Открытая закупка видна менеджерам и после срока — приём закрыт, но машины и цены на месте. */
    public function isPublic(): bool
    {
        return $this === self::Open;
    }
}
