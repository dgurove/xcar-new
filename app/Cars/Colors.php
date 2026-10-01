<?php

namespace App\Cars;

/**
 * Цвет кузова словом: «Белый», «Серый металлик», «Темно-синий». Одно написание на письма, документы, сканы и форму
 * ТС (раньше письмо давало «белый», документ — «Белый»). OCR пишет «Черый», «Бельый», «Зжелтый» — слово из пяти
 * букв и длиннее принимается с одной опечаткой. Чего нет в словаре — не цвет: «Начиная», «Автомобиля» из шапки
 * таблицы цветом не становятся.
 */
final class Colors
{
    /** Основы цветов: прилагательное в именительном падеже мужского рода. */
    private const BASE = ['белый', 'черный', 'серый', 'серебристый', 'синий', 'голубой', 'красный', 'бордовый', 'вишневый', 'зеленый', 'желтый',
        'оранжевый', 'коричневый', 'бежевый', 'золотистый', 'фиолетовый', 'розовый', 'бронзовый', 'графитовый', 'песочный', 'сиреневый', 'бирюзовый',
        'хаки', 'темный', 'светлый', 'антрацитовый', 'медный', 'кремовый', 'пурпурный', 'малиновый', 'оливковый', 'изумрудный', 'перламутровый'];

    /** Хвосты и приставки, которые остаются при цвете. */
    private const TAIL = ['металлик', 'перламутр', 'матовый', 'темно', 'светло', 'асфальт', 'мокрый'];

    /** Цвет по слову или фразе; не цвет — null. */
    public static function normalize(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = str_replace('ё', 'е', $value);
        if ($value === '' || mb_strlen($value) > 40) {
            return null;
        }
        // «мокрый асфальт» — цвет целиком.
        if (preg_match('/^мокр\w*\s+асфальт\w*$/u', $value)) {
            return 'Мокрый асфальт';
        }
        $words = preg_split('/[\s,]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        $color = false;
        foreach ($words as $word) {
            // «темно-синий»: каждая часть через дефис.
            $parts = [];
            foreach (explode('-', $word) as $part) {
                $fixed = self::word($part);
                if ($fixed === null) {
                    return $out && $color ? self::title($out) : null;
                }
                $color = $color || in_array(self::stem($fixed), array_map(fn ($b) => self::stem($b), self::BASE), true) && ! in_array($fixed, ['темный', 'светлый'], true);
                $parts[] = $fixed;
            }
            $out[] = implode('-', $parts);
        }

        return $color ? self::title($out) : null;
    }

    /** Слово словаря в мужском роде, как у машины («серая», «красн» → «серый», «красный»), с одной опечаткой. */
    private static function word(string $word): ?string
    {
        if (in_array($word, self::TAIL, true)) {
            return $word;
        }
        $stem = self::stem($word);
        foreach (self::BASE as $base) {
            if (self::stem($base) === $stem) {
                return $base;
            }
        }
        if (mb_strlen($word) < 5) {
            return null;
        }
        $near = array_values(array_filter(self::BASE, fn ($base) => self::distance(self::stem($base), $stem) <= 1));
        // «Черый»: на одну правку и «черный» (потеряна буква), и «серый» (замена). OCR чаще теряет или вставляет букву,
        // чем подменяет: при споре — вариант другой длины.
        if (count($near) > 1) {
            $near = array_values(array_filter($near, fn ($base) => mb_strlen(self::stem($base)) !== mb_strlen($stem)));
        }

        return count($near) === 1 ? $near[0] : null;
    }

    private static function stem(string $word): string
    {
        return (string) preg_replace('/(?:ый|ий|ой|ая|яя|ое|ее|ые|ие)$/u', '', $word);
    }

    /** Левенштейн по буквам, не по байтам: у кириллицы `levenshtein` считал бы каждую букву за две. */
    private static function distance(string $a, string $b): int
    {
        $a = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY);
        $b = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
        $row = range(0, count($b));
        foreach ($a as $i => $ca) {
            $prev = $row;
            $row = [$i + 1];
            foreach ($b as $j => $cb) {
                $row[$j + 1] = min($prev[$j + 1] + 1, $row[$j] + 1, $prev[$j] + ($ca === $cb ? 0 : 1));
            }
        }

        return $row[count($b)];
    }

    private static function title(array $words): string
    {
        $text = implode(' ', $words);

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
