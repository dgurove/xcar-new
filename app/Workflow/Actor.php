<?php

namespace App\Workflow;

use App\Cars\HasLabels;

/** Кто уводит с этапа этим исходом. Время двигает оффер само по истечении срока. */
enum Actor: string
{
    use HasLabels;

    case Staff = 'staff';
    case Manager = 'manager';
    case Timer = 'timer';
    case Keeper = 'keeper';     // ответственный за вывоз: назначенный менеджер, а без него — мы

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Мы',
            self::Manager => 'Менеджер',
            self::Timer => 'Время',
            self::Keeper => 'Ответственный за вывоз',
        };
    }
}
