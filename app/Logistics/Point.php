<?php

namespace App\Logistics;

use App\Cars\HasLabels;
use App\Offers\CarPlace;

/**
 * Куда исполнитель везёт машину (17.10.2026, владелец: «выбор + свой адрес»): в гараж менеджера сделки, к нам, на
 * парковку или по адресу. Точка сама ставит машине место (`CarPlace`) по «Привёз». Парковка — только адрес: дело ТС
 * на park.xcar вывоз не заводит (08.10.2026, «на park.xcar — только то, что на storage@»).
 */
enum Point: string
{
    use HasLabels;

    case Keeper = 'keeper';
    case Ours = 'ours';
    case Yard = 'yard';
    case Other = 'other';
    case Depot = 'depot';    // стоянка логиста (18.10.2026): промежуточная точка, дальше — следующий отрезок

    public function label(): string
    {
        return match ($this) {
            self::Keeper => 'К менеджеру',
            self::Ours => 'К нам',   // старые задачи до 18.10.2026: новых «к нам» без адреса нет
            self::Yard => 'На парковку',
            self::Other => 'Другой адрес',
            self::Depot => 'На стоянку',
        };
    }

    /** Где машина, когда её привезли: у менеджера, у нас, на нашей парковке или по чужому адресу. */
    public function place(): CarPlace
    {
        return match ($this) {
            self::Keeper => CarPlace::Keeper,
            self::Ours => CarPlace::WithUs,
            self::Yard => CarPlace::Ours,
            self::Other => CarPlace::Elsewhere,
            self::Depot => CarPlace::Depot,
        };
    }

    /**
     * Значение строки «Куда» из формы: точка и у парковки — её номер. Каждая наша парковка — своя строка
     * (`yard:5`, 18.10.2026, владелец: «„к нам“ — это куда, непонятно»); старое `yard` с полем `to_yard_id` — тоже.
     *
     * @return array{0: ?self, 1: ?int}
     */
    public static function read(array $data): array
    {
        $value = (string) ($data['destination'] ?? '');
        $point = preg_match('/^yard:(\d+)$/', $value, $m) ? self::Yard : self::tryFrom($value);
        $yard = $point === self::Yard ? (int) ($m[1] ?? $data['to_yard_id'] ?? 0) : 0;

        // Чужой номер — «какая парковка?», а не ошибка базы.
        return [$point, $yard && array_key_exists($yard, Choices::yards()) ? $yard : null];
    }

    /**
     * Строки «Куда» формы — значение → подпись: менеджеру сделки (если он есть), каждая наша парковка по названию, другой
     * адрес. «К нам» без адреса больше не предлагается: это не место. `$keeper` — подпись строки менеджера.
     *
     * @return array<string, string>
     */
    public static function choices(?string $keeper): array
    {
        return array_filter([self::Keeper->value => $keeper])
            + collect(Choices::yards())->mapWithKeys(fn ($name, $id) => ['yard:'.$id => 'Парковка '.$name])->all()
            + [self::Other->value => self::Other->label()];
    }
}
