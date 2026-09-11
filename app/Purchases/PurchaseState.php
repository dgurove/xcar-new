<?php

namespace App\Purchases;

use App\Cars\HasLabels;

enum PurchaseState: string
{
    use HasLabels;

    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Open => 'Приём цен',
            self::Closed => 'Приём закрыт',
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

    public function isPublic(): bool
    {
        return in_array($this, [self::Open, self::Closed], true);
    }
}
