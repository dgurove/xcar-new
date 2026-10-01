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
    public static function normalize(?string $value, bool $fuzzy = true): ?string
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
        // «Красный, черный» — два цвета: каждый по отдельности, запятая остаётся.
        $pieces = [];
        foreach (preg_split('/\s*,\s*/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            $color = self::piece($piece, $fuzzy);
            if ($color === null) {
                return null;
            }
            $pieces[] = $color;
        }

        return $pieces ? self::title([implode(', ', $pieces)]) : null;
    }

    /**
     * Один цвет словами («серый металлик», «черно-белый»). Незнакомое слово — не цвет целиком: «Белая ночь» не
     * становится «Белым», иначе человек потерял бы свой цвет. Без `$fuzzy` (форма) слова остаются как написаны —
     * опечатки правятся только у OCR.
     */
    private static function piece(string $piece, bool $fuzzy): ?string
    {
        $words = [];
        $color = false;
        foreach (preg_split('/\s+/u', $piece, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $parts = explode('-', $word);
            $fixed = [];
            foreach ($parts as $i => $part) {
                $known = self::word($part, $fuzzy, $i < count($parts) - 1);
                if ($known === null) {
                    return null;
                }
                $color = $color || (in_array(self::stem($known), array_map(fn ($b) => self::stem($b), self::BASE), true) && ! in_array($known, ['темный', 'светлый'], true))
                    || (bool) preg_match('/[ое]$/u', $known) && $i < count($parts) - 1 && ! in_array($known, self::TAIL, true);
                $fixed[] = $fuzzy ? $known : $part;
            }
            $words[] = implode('-', $fixed);
        }

        return $color ? implode(' ', $words) : null;
    }

    /**
     * Слово словаря в мужском роде, как у машины («серая», «красн» → «серый», «красный»), с одной опечаткой при `$fuzzy`.
     * Первая часть составного цвета («черно-», «серо-», «сине-») остаётся как есть.
     */
    private static function word(string $word, bool $fuzzy = true, bool $prefix = false): ?string
    {
        if (in_array($word, self::TAIL, true)) {
            return $word;
        }
        if ($prefix && preg_match('/^(.+)[ое]$/u', $word, $m)) {
            foreach (self::BASE as $base) {
                if (self::stem($base) === $m[1]) {
                    return $word;
                }
            }
        }
        $stem = self::stem($word);
        foreach (self::BASE as $base) {
            if (self::stem($base) === $stem) {
                return $base;
            }
        }
        if (! $fuzzy || mb_strlen($word) < 5) {
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
