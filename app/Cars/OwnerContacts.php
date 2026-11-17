<?php

namespace App\Cars;

use App\Support\Phone;

/**
 * Контакты владельца машины списком (17.11.2026, Т-Страхование: в письме двое — «Нужина Елена Александровна +7(906)… /
 * Нужин Владислав Николаевич +7(967)…»): `cars.insured_contacts` `[{name, phone}]`. Первый контакт — ещё и в скалярных
 * `insured_name` / `insured_phone`, телефон второго — в `insured_phone2`: их читают логистика (`Transport::ownerName`),
 * уведомления и прежние экраны. Одна дверь чтения (`of`) и записи (`fields`).
 */
final class OwnerContacts
{
    /**
     * Контакты для шторки шага: записанный список, а без него — прежние поля (имя, телефон, второй телефон без имени, чтобы
     * при сохранении шторки он не потерялся).
     *
     * @return list<array{name: ?string, phone: ?string}>
     */
    public static function of(object $car): array
    {
        return self::listed($car) ?: self::clean([
            ['name' => $car->insured_name ?? null, 'phone' => $car->insured_phone ?? null],
            ['name' => null, 'phone' => $car->insured_phone2 ?? null],
        ]);
    }

    /**
     * Только записанный список — по нему виды решают, показывать ли контакты списком: второй телефон одного человека
     * (`insured_phone2`) — не второй контакт.
     *
     * @return list<array{name: ?string, phone: ?string}>
     */
    public static function listed(object $car): array
    {
        return self::clean((array) ($car->insured_contacts ?? []));
    }

    /**
     * Строки формы или письма — без пустых, телефон одного вида, один номер — один раз.
     *
     * @return list<array{name: ?string, phone: ?string}>
     */
    public static function clean(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['name'] ?? '')));
            $raw = trim((string) ($row['phone'] ?? ''));
            $normalized = Phone::normalize($raw);
            $phone = $normalized ? Phone::format($normalized) : ($raw !== '' ? $raw : null);
            if ($name === '' && $phone === null) {
                continue;
            }
            $key = $normalized ?: mb_strtolower($name.'|'.$raw);
            $out[$key] ??= ['name' => $name !== '' ? mb_substr($name, 0, 80) : null, 'phone' => $phone];
        }

        return array_values($out);
    }

    /**
     * Поля машины по списку: сам список (имя, телефон и второй телефон из него ставит `sync` при сохранении машины). Пустой
     * список — и прежние поля пусто: очистили все контакты — значит, владельца нет.
     */
    public static function fields(array $contacts): array
    {
        $list = self::clean($contacts);

        return $list ? ['insured_contacts' => $list] : ['insured_contacts' => null, 'insured_name' => null, 'insured_phone' => null, 'insured_phone2' => null];
    }

    /**
     * Одна правда о владельце (`Car::saving`): сменили список — из него прежние поля (первый контакт — имя и телефон, телефон
     * второго — второй телефон); сменили прежние поля мимо списка (логистика `SetDetails`, редактор, бот) при непустом
     * списке — первый контакт и телефон второго из них. Иначе список устаревал и шторка, CRM и менеджер видели старый номер.
     */
    public static function sync(Car $car): void
    {
        if ($car->isDirty('insured_contacts')) {
            $list = self::listed($car);
            $car->insured_contacts = $list ?: null;
            $car->insured_name = $list[0]['name'] ?? null;
            $car->insured_phone = $list[0]['phone'] ?? null;
            $car->insured_phone2 = $list[1]['phone'] ?? null;

            return;
        }
        $list = self::listed($car);
        if (! $list || ! $car->isDirty(['insured_name', 'insured_phone', 'insured_phone2'])) {
            return;
        }
        $list[0] = ['name' => $car->insured_name, 'phone' => $car->insured_phone];
        if ($car->isDirty('insured_phone2')) {
            if (isset($list[1])) {
                $list[1]['phone'] = $car->insured_phone2;
            } elseif ($car->insured_phone2) {
                $list[1] = ['name' => null, 'phone' => $car->insured_phone2];
            }
        }
        $car->insured_contacts = self::clean($list) ?: null;
    }
}
