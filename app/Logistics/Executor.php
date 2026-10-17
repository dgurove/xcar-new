<?php

namespace App\Logistics;

use App\Cars\HasLabels;

/**
 * Кто вывозит (17.10.2026, владелец): регионы — Татьяна (логист) своими исполнителями, она вводит расстояние и цену,
 * платим ей; наши люди ООО «ПРАЙМ» (Иван, Сергей…); другой менеджер (`Partner`: «вывозит логистика — может быть кто угодно
 * из других менеджеров, кроме менеджера сделки») — исполнитель логистики, как наши. `Manager` — менеджер сделки забирает
 * сам: решает «Принять», это не логистика (`Transport::scopeInLogistics`). Решает админ — правилом по городу не распределить.
 */
enum Executor: string
{
    use HasLabels;

    case Logist = 'logist';
    case Manager = 'manager';
    case Staff = 'staff';
    case Partner = 'partner';

    public function label(): string
    {
        return match ($this) {
            self::Logist => 'Логист',
            self::Manager => 'Менеджер',
            self::Staff => 'Наши',
            self::Partner => 'Менеджер',
        };
    }
}
