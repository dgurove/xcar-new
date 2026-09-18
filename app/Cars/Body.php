<?php

namespace App\Cars;

enum Body: string
{
    use HasLabels;

    case Sedan = 'sedan';
    case Hatchback = 'hatchback';
    case Wagon = 'wagon';
    case Suv = 'suv';
    case Crossover = 'crossover';
    case Coupe = 'coupe';
    case Minivan = 'minivan';
    case Pickup = 'pickup';
    case Van = 'van';
    case Truck = 'truck';
    case Bus = 'bus';
    case Special = 'special';
    case Moto = 'moto';

    public function label(): string
    {
        return match ($this) {
            self::Sedan => 'Седан',
            self::Hatchback => 'Хэтчбек',
            self::Wagon => 'Универсал',
            self::Suv => 'Внедорожник',
            self::Crossover => 'Кроссовер',
            self::Coupe => 'Купе',
            self::Minivan => 'Минивэн',
            self::Pickup => 'Пикап',
            self::Van => 'Фургон',
            self::Truck => 'Грузовик',
            self::Bus => 'Автобус',
            self::Special => 'Спецтехника',
            self::Moto => 'Мототехника',
        };
    }

    /** Категория прайса стоянки по кузову. */
    public function category(): Category
    {
        return match ($this) {
            self::Pickup, self::Van => Category::Light,
            self::Truck, self::Bus => Category::Truck,
            self::Special => Category::Special,
            self::Moto => Category::Moto,
            default => Category::Passenger,
        };
    }
}
