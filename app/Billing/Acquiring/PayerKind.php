<?php

namespace App\Billing\Acquiring;

use App\Cars\HasLabels;

/** Кто платит по ссылке: сам менеджер, его покупатель из кабинета или другой человек по имени и телефону. */
enum PayerKind: string
{
    use HasLabels;

    case Self = 'self';
    case Buyer = 'buyer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Self => 'Я',
            self::Buyer => 'Покупатель',
            self::Other => 'Другой человек',
        };
    }
}
