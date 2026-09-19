<?php

namespace App\Vendors;

use App\Cars\HasLabels;

/** Кто присылает машины: страховая, лизинговая, корпоративный парк, физлицо, свой транспорт, банк-залогодержатель. */
enum Kind: string
{
    use HasLabels;

    case Insurer = 'insurer';
    case Leasing = 'leasing';
    case Fleet = 'fleet';
    case Person = 'person';
    case Own = 'own';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Insurer => 'Страховая',
            self::Leasing => 'Лизинговая',
            self::Fleet => 'Корпоративный парк',
            self::Person => 'Физическое лицо',
            self::Own => 'Собственный транспорт',
            self::Bank => 'Банк',
        };
    }

    public function plural(): string
    {
        return match ($this) {
            self::Insurer => 'Страховые',
            self::Leasing => 'Лизинговые',
            self::Fleet => 'Корп. парки',
            self::Person => 'Физлица',
            self::Own => 'Свой транспорт',
            self::Bank => 'Банки',
        };
    }

    /** Свой транспорт не платит за хранение и счетов не получает. */
    public function billable(): bool
    {
        return $this !== self::Own;
    }
}
