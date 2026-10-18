<?php

namespace App\Logistics;

use App\Cars\HasLabels;

/**
 * Как назначен вывоз (`transports.channel`, 18.10.2026: было `executor`, и «executor = logist» читалось «исполнитель —
 * логист», а логист сам не везёт, он распределяет). Решает админ — правилом по городу не распределить:
 * — через логиста (регионы, Татьяна): задача у логиста (`logist_id`), исполнителя — своего человека (`Role::Contractor`),
 *   расстояние и цену ставит он; платим ему;
 * — наши люди ООО «ПРАЙМ» (`Role::Driver`: Иван, Сергей…) — исполнитель сразу;
 * — другой менеджер («вывозит логистика — может быть кто угодно из других менеджеров, кроме менеджера сделки») —
 *   исполнитель сразу, как наши;
 * — менеджер сделки забирает сам: решает «Принять», это не логистика (`Transport::scopeInLogistics`).
 * Исполнитель (`executor_id`) — всегда тот, кто едет; логист им не бывает.
 */
enum Channel: string
{
    use HasLabels;

    case Logist = 'logist';
    case Manager = 'manager';
    case Staff = 'staff';
    case Partner = 'partner';

    public function label(): string
    {
        return match ($this) {
            self::Logist => 'Через логиста',
            self::Manager => 'Менеджер сделки',
            self::Staff => 'Наши',
            self::Partner => 'Другой менеджер',
        };
    }

    /** Сегмент окна «Кто вывозит»: коротко, человек выбирается строкой ниже. */
    public function short(): string
    {
        return match ($this) {
            self::Logist => 'Логист',
            self::Manager, self::Partner => 'Менеджер',
            self::Staff => 'Наши',
        };
    }
}
