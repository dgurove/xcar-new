<?php

namespace App\Cars;

/**
 * Категория ТС для стоянки и прайса: по ней считаются хранение и эвакуация.
 * Кузов оффера и категория закупок сводятся сюда, у машины на стоянке она своя.
 */
enum Category: string
{
    use HasLabels;

    case Passenger = 'passenger';
    case Light = 'light';
    case Truck = 'truck';
    case Trailer = 'trailer';
    case Special = 'special';
    case Moto = 'moto';

    public function label(): string
    {
        return match ($this) {
            self::Passenger => 'Легковой',
            self::Light => 'Лёгкий коммерческий',
            self::Truck => 'Грузовой',
            self::Trailer => 'Прицеп',
            self::Special => 'Спецтехника',
            self::Moto => 'Мото',
        };
    }

    /** Подсказка по словам письма: «Тип ТС грузовики», «полуприцеп», «Газель». */
    public static function guess(?string $text): ?self
    {
        $t = mb_strtolower(str_replace('ё', 'е', (string) $text));
        if ($t === '') {
            return null;
        }

        return match (true) {
            str_contains($t, 'полуприцеп') || str_contains($t, 'прицеп') => self::Trailer,
            str_contains($t, 'погрузчик') || str_contains($t, 'спецтехник') || str_contains($t, 'экскаватор') || str_contains($t, 'кран') => self::Special,
            str_contains($t, 'мотоцикл') || str_contains($t, 'мототехник') || str_contains($t, 'скутер') || str_contains($t, 'квадроцикл') => self::Moto,
            str_contains($t, 'газель') || str_contains($t, 'микроавтобус') || str_contains($t, 'фургон') || str_contains($t, 'легкий коммерческий') => self::Light,
            str_contains($t, 'грузов') || str_contains($t, 'тягач') || str_contains($t, 'автобус') || str_contains($t, 'самосвал') => self::Truck,
            str_contains($t, 'легков') => self::Passenger,
            default => null,
        };
    }
}
