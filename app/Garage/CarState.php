<?php

namespace App\Garage;

use App\Cars\HasLabels;

enum CarState: string
{
    use HasLabels;

    case Repair = 'repair';       // у менеджера, идёт ремонт
    case Sold = 'sold';           // продана, расчёт ещё не закрыт
    case Settled = 'settled';     // счёт выставлен и оплачен

    public function label(): string
    {
        return match ($this) {
            self::Repair => 'Чинится',
            self::Sold => 'Продана',
            self::Settled => 'Расчёт закрыт',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Repair => 'open',
            self::Sold => 'urgent',
            self::Settled => 'closed',
        };
    }
}
