<?php

namespace App\Cars;

enum Drive: string
{
    use HasLabels;

    case Front = 'fwd';
    case Rear = 'rwd';
    case All = 'awd';

    public function label(): string
    {
        return match ($this) {
            self::Front => 'Передний',
            self::Rear => 'Задний',
            self::All => 'Полный',
        };
    }
}
