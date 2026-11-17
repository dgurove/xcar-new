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
     * Контакты машины: список, а без него — прежние поля (имя, телефон, второй телефон без имени).
     *
     * @return list<array{name: ?string, phone: ?string}>
     */
    public static function of(object $car): array
    {
        $list = self::clean((array) ($car->insured_contacts ?? []));
        if ($list) {
            return $list;
        }

        return self::clean([
            ['name' => $car->insured_name ?? null, 'phone' => $car->insured_phone ?? null],
            ['name' => null, 'phone' => $car->insured_phone2 ?? null],
        ]);
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

    /** Поля машины по списку: сам список, первый контакт — имя и телефон, телефон второго — второй телефон. */
    public static function fields(array $contacts): array
    {
        $list = self::clean($contacts);

        return [
            'insured_contacts' => $list ?: null,
            'insured_name' => $list[0]['name'] ?? null,
            'insured_phone' => $list[0]['phone'] ?? null,
            'insured_phone2' => $list[1]['phone'] ?? null,
        ];
    }
}
