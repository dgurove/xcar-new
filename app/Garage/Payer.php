<?php

namespace App\Garage;

use App\Cars\HasLabels;

/** Кто заплатил за расход: менеджер из своего кармана или мы. Ставится по тому, кто записал. */
enum Payer: string
{
    use HasLabels;

    case Manager = 'manager';
    case Xcar = 'xcar';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Менеджер',
            self::Xcar => 'XCar',
        };
    }
}
