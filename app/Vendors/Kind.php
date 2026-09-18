<?php

namespace App\Vendors;

use App\Cars\HasLabels;

/** Кто присылает машины: страховая, лизинг, банк-залогодержатель, прочие. */
enum Kind: string
{
    use HasLabels;

    case Insurer = 'insurer';
    case Leasing = 'leasing';
    case Bank = 'bank';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Insurer => 'Страховая',
            self::Leasing => 'Лизинг',
            self::Bank => 'Банк',
            self::Other => 'Прочее',
        };
    }

    public function plural(): string
    {
        return match ($this) {
            self::Insurer => 'Страховые',
            self::Leasing => 'Лизинг',
            self::Bank => 'Банки',
            self::Other => 'Прочие',
        };
    }
}
