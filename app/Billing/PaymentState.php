<?php

namespace App\Billing;

use App\Cars\HasLabels;

/** Оплата: подтверждена сотрудником (в `paid`), заявлена менеджером с платёжкой (ждёт) или не поступила. */
enum PaymentState: string
{
    use HasLabels;

    case Confirmed = 'confirmed';
    case Claimed = 'claimed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Принята',
            self::Claimed => 'Ждёт подтверждения',
            self::Rejected => 'Не поступила',
        };
    }
}
