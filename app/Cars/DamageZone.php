<?php

namespace App\Cars;

enum DamageZone: string
{
    use HasLabels;

    case Front = 'front';
    case Rear = 'rear';
    case Left = 'left';
    case Right = 'right';
    case Roof = 'roof';
    case Bottom = 'bottom';
    case Glass = 'glass';
    case Interior = 'interior';

    public function label(): string
    {
        return match ($this) {
            self::Front => 'Перед',
            self::Rear => 'Зад',
            self::Left => 'Левый борт',
            self::Right => 'Правый борт',
            self::Roof => 'Крыша',
            self::Bottom => 'Днище',
            self::Glass => 'Стёкла',
            self::Interior => 'Салон',
        };
    }
}
