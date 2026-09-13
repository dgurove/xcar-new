<?php

namespace App\Purchases;

/**
 * Как закупка делится на сайте: легковые и всё остальное.
 *
 * Категорий пять, а человек приходит либо за легковыми, либо за техникой,
 * поэтому одна присланная закупка показывается двумя — «Закупка № 1 легковые»
 * и «Закупка № 1 грузовые» — со своим счётом готовности. «Грузовые» здесь
 * условное имя всего нелегкового. Закупка при этом одна: файл, выгрузка
 * обратно и срок приёма общие, делится она только на экране.
 */
enum Group: string
{
    case Passenger = 'passenger';
    case Freight = 'freight';

    public function label(): string
    {
        return match ($this) {
            self::Passenger => 'легковые',
            self::Freight => 'грузовые',
        };
    }

    /** @return list<Kind> */
    public function kinds(): array
    {
        return match ($this) {
            self::Passenger => [Kind::Passenger],
            self::Freight => [Kind::Light, Kind::Truck, Kind::Special, Kind::Other],
        };
    }

    /** @return list<string> */
    public function kindValues(): array
    {
        return array_map(fn (Kind $k) => $k->value, $this->kinds());
    }

    public static function of(Kind $kind): self
    {
        return $kind === Kind::Passenger ? self::Passenger : self::Freight;
    }
}
