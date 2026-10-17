<?php

namespace App\Logistics;

use App\Cars\HasLabels;

/**
 * Кто вывозит (17.10.2026, владелец): регионы — Татьяна (логист) своими исполнителями, она вводит расстояние и цену,
 * платим ей; менеджер, которому мы подтвердили сделку (в его гараж или его покупателю); наши люди ООО «ПРАЙМ»
 * (Иван, Сергей…). Решает админ — правилом по городу не распределить.
 */
enum Executor: string
{
    use HasLabels;

    case Logist = 'logist';
    case Manager = 'manager';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Logist => 'Логист',
            self::Manager => 'Менеджер',
            self::Staff => 'Наши',
        };
    }
}
