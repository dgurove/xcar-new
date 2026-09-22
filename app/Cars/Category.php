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
    case Long = 'long';
    case Trailer = 'trailer';
    case Special = 'special';
    case Moto = 'moto';

    public function label(): string
    {
        return match ($this) {
            self::Passenger => 'Легковой',
            self::Light => 'Лёгкий коммерческий',
            self::Truck => 'Грузовой',
            self::Long => 'Длинномер',
            self::Trailer => 'Прицеп',
            self::Special => 'Спецтехника',
            self::Moto => 'Мото',
        };
    }

    /** Подсказка по словам письма или по марке: «Тип ТС грузовики», «полуприцеп», «Газель», «КАМАЗ», «Sitrak». */
    public static function guess(?string $text): ?self
    {
        $t = mb_strtolower(str_replace('ё', 'е', (string) $text));
        if ($t === '') {
            return null;
        }
        $has = fn (string ...$words) => array_any($words, fn ($w) => str_contains($t, $w));

        return match (true) {
            $has('полуприцеп', 'длинномер', 'фура', 'автопоезд', 'тонар', 'кроне', 'krone', 'schmitz', 'шмитц') => self::Long,
            $has('прицеп') => self::Trailer,
            $has('погрузчик', 'спецтехник', 'экскаватор', 'кран', 'бетономешалка', 'бетоносмесител', 'манипулятор') => self::Special,
            $has('мотоцикл', 'мототехник', 'скутер', 'квадроцикл') => self::Moto,
            $has('газель', 'микроавтобус', 'фургон', 'легкий коммерческий', 'соболь', 'газ 2', 'gaz 2', 'газ 3', 'луидор', 'luidor', '3009', 'toano', 'jac n', 'белава', 'belava', 'boxer', 'transit', 'транзит', 'sprinter', 'спринтер', 'largus фургон', 'daily', 'isuzu clw', 'nqr', 'npr') => self::Light,
            $has('тягач') => self::Long,
            $has('грузов', 'автобус', 'самосвал', 'камаз', 'kamaz', 'sitrak', 'ситрак', 'actros', 'актрос', 'man gpm', 'man tg', 'shacman', 'faw j', 'howo', 'маз ', 'урал', 'isuzu') => self::Truck,
            $has('легков') => self::Passenger,
            default => null,
        };
    }
}
