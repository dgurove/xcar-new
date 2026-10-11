<?php

namespace App\Billing\Passport;

/**
 * Поля паспорта РФ из текста OCR (11.10.2026, владелец: «менеджеры легко ошибаются, пусть прикрепляют скан»).
 *
 * Разворот с фото: главное — машиночитаемая зона внутреннего паспорта (с 2011, две строки по 44 знака внизу): серия и
 * номер, дата рождения, пол, дата выдачи и код подразделения с контрольными цифрами. Сошлись цифры — значение верное
 * (`checked`). ФИО в зоне — русская транслитерация зоны (Ч → 3, Я → 8…), по ней выбираются кириллические слова
 * разворота. Кем выдан и место рождения — строками у своих мест; зоны нет (старый паспорт, не прочиталась) — всё по
 * подписям. Прописка: штамп «Зарегистрирован» → адрес (последний по дате, «снят с учёта» — мимо).
 * Ключи полей — колонки `App\Billing\Party`; даты — Y-m-d (поля type=date).
 */
final class PassportText
{
    /** Транслитерация зоны внутреннего паспорта → кириллица. */
    private const MRZ_RU = [
        'A' => 'А', 'B' => 'Б', 'V' => 'В', 'G' => 'Г', 'D' => 'Д', 'E' => 'Е', '2' => 'Ё', 'J' => 'Ж', 'Z' => 'З',
        'I' => 'И', 'Q' => 'Й', 'K' => 'К', 'L' => 'Л', 'M' => 'М', 'N' => 'Н', 'O' => 'О', 'P' => 'П', 'R' => 'Р',
        'S' => 'С', 'T' => 'Т', 'U' => 'У', 'F' => 'Ф', 'H' => 'Х', 'C' => 'Ц', '3' => 'Ч', '4' => 'Ш', 'W' => 'Щ',
        'X' => 'Ъ', 'Y' => 'Ы', '9' => 'Ь', '6' => 'Э', '7' => 'Ю', '8' => 'Я',
    ];

    /** Латиница, похожая на кириллицу, — OCR путает алфавиты в одном слове. */
    private const LOOKALIKE = [
        'A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н', 'K' => 'К', 'M' => 'М', 'O' => 'О', 'P' => 'Р',
        'T' => 'Т', 'X' => 'Х', 'Y' => 'У', '3' => 'З', '0' => 'О',
    ];

    /** Подписи бланка — не значения. */
    private const LABELS = '/^(РОССИЙСКАЯ|ФЕДЕРАЦИЯ|ПАСПОРТ|ВЫДАН|ДАТА|ВЫДАЧИ|КОД|ПОДРАЗДЕЛЕНИЯ|ЛИЧНЫЙ|ПОДПИСЬ|ФАМИЛИЯ|ИМЯ|ОТЧЕСТВО|ПОЛ|МЕСТО|РОЖДЕНИЯ|РОССИЯ|RUSSIA|М\.?П\.?)$/u';

    /**
     * @return array{fields: array<string, string>, checked: list<string>}
     */
    public static function main(string $text): array
    {
        $lines = self::lines($text);
        $mrz = self::mrz($lines);
        $fields = [];
        $checked = [];

        // Код подразделения, даты — по виду; зона, если сошлась, — главнее.
        if (preg_match('/(?<!\d)(\d{3})\s*[-–—]\s*(\d{3})(?!\d)/u', $text, $m)) {
            $fields['passport_code'] = $m[1].'-'.$m[2];
        }
        $dates = self::dates($text);
        if ($dates) {
            $fields['passport_issued_at'] = $dates[0];
            if (count($dates) > 1) {
                $fields['birth_at'] = $dates[count($dates) - 1];
            }
        }
        // Серия и номер красным вертикально у обеих страниц: «87 11 524350». Без зоны верим, только если обе сошлись —
        // неверный номер в договоре хуже пустого поля.
        preg_match_all('/(?<!\d)(\d{2})\s?(\d{2})\s{0,3}(\d{6})(?!\d)/u', preg_replace('/[OО]/u', '0', $text), $sn, PREG_SET_ORDER);
        $seen = array_count_values(array_map(fn ($m) => "{$m[1]} {$m[2]} {$m[3]}", $sn));
        arsort($seen);
        if ($seen && reset($seen) >= 2) {
            $fields['passport'] = array_key_first($seen);
        }

        // Зона главнее, только где сошлись контрольные цифры; без них — лишь на пустое место, а номер паспорта — никогда:
        // неверный номер в договоре хуже пустого поля.
        if ($mrz) {
            foreach (['passport', 'birth_at', 'passport_issued_at', 'passport_code'] as $key) {
                if (! isset($mrz[$key])) {
                    continue;
                }
                if (in_array($key, $mrz['checked'], true)) {
                    $fields[$key] = $mrz[$key];
                    $checked[] = $key;
                } elseif ($key !== 'passport' && ! isset($fields[$key])) {
                    $fields[$key] = $mrz[$key];
                }
            }
        }

        if ($name = self::name($lines, $mrz, $checked)) {
            $fields['name'] = $name;
        }
        if ($issued = self::issuer($lines)) {
            $fields['passport_issued'] = $issued;
        }
        if ($place = self::birthPlace($lines)) {
            $fields['birth_place'] = $place;
        }

        return ['fields' => $fields, 'checked' => array_values(array_unique($checked))];
    }

    /**
     * Адрес из штампа «Зарегистрирован»: последний по дате, «снят с учёта» — мимо.
     *
     * @return array{fields: array<string, string>, checked: list<string>}
     */
    public static function registration(string $text): array
    {
        $lines = self::lines($text);
        $stamps = [];
        $current = null;
        foreach ($lines as $line) {
            $up = self::cyr(mb_strtoupper($line));
            if (preg_match('/З?АРЕГИСТР|РЕГИСТРИРОВАН/u', $up) && ! preg_match('/СНЯТ|РЕГИСТРАЦИОННОГО/u', $up)) {
                $current !== null && $stamps[] = $current;
                $current = ['date' => null, 'lines' => []];

                continue;
            }
            if (preg_match('/СНЯТ\b|С РЕГИСТРАЦИОННОГО/u', $up)) {
                $current !== null && $stamps[] = $current;
                $current = null;

                continue;
            }
            if ($current === null) {
                continue;
            }
            if (! $current['date'] && ($d = self::dates($line))) {
                $current['date'] = $d[0];

                continue;
            }
            $current['lines'][] = $line;
        }
        $current !== null && $stamps[] = $current;
        if (! $stamps) {
            return ['fields' => [], 'checked' => []];
        }
        usort($stamps, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
        $address = self::address($stamps[0]['lines']);

        return ['fields' => $address !== '' ? ['reg_address' => $address] : [], 'checked' => []];
    }

    /** Строки штампа → «САМАРСКАЯ ОБЛ., ГОР. САМАРА, УЛ. МОЛОДОГВАРДЕЙСКАЯ, Д. 151, КВ. 23». */
    private static function address(array $lines): string
    {
        $parts = [];
        foreach ($lines as $line) {
            $up = self::fixPrefix(self::cyr(mb_strtoupper(trim($line)), true));
            // Кем зарегистрирован, код подразделения, подпись — конец адреса («ГУМВД», «ФУМВД» — тот же «ГУ МВД»).
            if (preg_match('/МВД|УФМС|ОВД|ОВМ|ОТДЕЛ|МИГРАЦ|ПАСПОРТН/u', $up) || preg_match('/^\d{3}-\d{3}|ЗАВЕРИЛ|ПОДПИСЬ/u', $up)) {
                break;
            }
            // «151 КВ.:23» без подписи «Дом»: номер дома и квартира.
            if (preg_match('/^([\dА-Я\/]+)\s+КВ\.?\s*:?\s*([\dА-Я]+)$/u', $up, $hk)) {
                $parts[] = 'Д. '.$hk[1];
                $parts[] = 'КВ. '.$hk[2];

                continue;
            }
            $up = preg_replace('/\s{2,}/u', ' ', $up);
            // Подпись поля штампа: «Рег-н:», «Пункт:», «Улица:», «Дом:», «Кв.:».
            if (preg_match('/^(?:РЕГ[-.\s]*Н|РЕГИОН|ОБЛАСТЬ|РАЙОН|Р-Н|ПУНКТ|НАС\.?\s*ПУНКТ|У[ЛП]ИЦА|УЛ|ДОМ|КОРП|КВ|КВАРТИРА)\b\.?\s*:?\s*(.*)$/u', $up, $m) && ! preg_match('/^(УЛ\.|Р-Н\s)/u', $up)) {
                $label = mb_substr($up, 0, 2);
                $value = trim($m[1], " :.\t");
                // «Дом: 151   Кв.: 23» в одной строке.
                if (preg_match('/^ДО/u', $label)) {
                    if (preg_match('/^([\dА-Я\/]+)(?:.*?КВ\.?\s*:?\s*([\dА-Я]+))?/u', $value, $h)) {
                        $parts[] = 'Д. '.$h[1];
                        ! empty($h[2]) && $parts[] = 'КВ. '.$h[2];
                    }

                    continue;
                }
                if (preg_match('/^КВ/u', $label)) {
                    $value !== '' && $parts[] = 'КВ. '.$value;

                    continue;
                }
                $value !== '' && $parts[] = $value;

                continue;
            }
            if (preg_match('/[А-Я]{2,}|\d/u', $up)) {
                $parts[] = trim($up, ' :');
            }
        }

        return implode(', ', array_filter($parts));
    }

    /**
     * Зона внутреннего паспорта: строка 1 «PNRUS ФАМИЛИЯ<<ИМЯ<ОТЧЕСТВО», строка 2 «серия(3)+номер, к, RUS, рождение, к,
     * пол, <<<<<<<, серия(1)+выдан+код, <, к, к» — 44 знака. Строки ищутся в склеенном тексте: на фото с наклоном OCR
     * рвёт их и перемешивает. Знаки «<» OCR теряет или читает буквами (K, C, 6) — сначала гибкий шаблон, затем по позициям.
     */
    private static function mrz(array $lines): ?array
    {
        $flat = implode("\n", array_map(fn ($l) => strtoupper(preg_replace('/\s+/u', '', self::latin($l))), $lines));
        $one = str_replace("\n", '', $flat);
        $out = ['checked' => []];
        $found = false;

        $line2 = null;
        // Гибкий: «<» сколько угодно, пол — любая буква (F читается как E).
        if (preg_match_all('/([0-9OQDILZSBGT]{9})([0-9OQDILZSBGT])R[UJ][S58]([0-9OQDILZSBGT]{6})([0-9OQDILZSBGT])([A-Z])[<KC]*([0-9OQDILZSBGT])([0-9OQDILZSBGT]{6})([0-9OQDILZSBGT]{6})[<KC]*([0-9OQDILZSBGT])?/', $one, $all, PREG_SET_ORDER)) {
            $line2 = $all[0];
        } elseif (preg_match('/([0-9A-Z<]{10})R[UJ][S58]([0-9A-Z<]{31})/', $one, $m)) {
            // По позициям: после RUS — рождение 6, к, пол, 7 знаков срока, серия(1)+выдан(6)+код(6), <, к.
            $t = $m[2];
            $line2 = [0, substr($m[1], 0, 9), substr($m[1], 9, 1), substr($t, 0, 6), substr($t, 6, 1), substr($t, 7, 1), substr($t, 15, 1), substr($t, 16, 6), substr($t, 22, 6), substr($t, 29, 1)];
        }
        if ($line2) {
            $d = fn ($v) => strtr((string) $v, ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'B' => '8', 'G' => '6', 'T' => '7']);
            [$num, $numCheck, $dob, $dobCheck, $sex, $serLast, $issued, $code] = [$d($line2[1]), $d($line2[2]), $d($line2[3]), $d($line2[4]), $line2[5], $d($line2[6]), $d($line2[7]), $d($line2[8])];
            $optCheck = $d($line2[9] ?? '');
            if (ctype_digit($num.$numCheck.$dob.$serLast.$issued.$code)) {
                $found = true;
                $out['passport'] = substr($num, 0, 2).' '.substr($num, 2, 1).$serLast.' '.substr($num, 3);
                $out['birth_at'] = self::yymmdd($dob, true);
                $out['passport_issued_at'] = self::yymmdd($issued, false);
                $out['passport_code'] = substr($code, 0, 3).'-'.substr($code, 3);
                // Серия делится: три цифры — у номера, последняя — у даты выдачи; верна, только если сошлись обе части.
                if (self::check($num) === $numCheck && $optCheck !== '' && self::check($serLast.$issued.$code.'<') === $optCheck) {
                    array_push($out['checked'], 'passport', 'passport_issued_at', 'passport_code');
                }
                if (self::check($dob) === $dobCheck) {
                    $out['checked'][] = 'birth_at';
                }
                $out['sex'] = $sex === 'M' ? 'M' : 'F';
            }
        }

        // Строка 1: «PNRUS…» до цифр строки 2 или конца строки.
        if (preg_match('/P[NH]?R[UJ][S58]([A-Z0-9<]{6,})/', $flat, $m)) {
            $body = preg_replace('/[0-9]{9,}.*$/', '', $m[1]);
            // «<» на краях слов OCR читает K или C: «<<» между фамилией и именем — и «KK», «<K», «C<».
            $body = rtrim(preg_replace('/(?<=<)[KC]|[KC](?=<)/', '<', $body), '<KC');
            $found = true;
            $out['mrz_body'] = $body;
        }

        return $found ? $out : null;
    }

    /**
     * ФИО кириллицей с разворота: три слова, чья транслитерация зоны вместе ближе всего к строке 1 зоны (OCR рвёт и
     * склеивает «<», поэтому сравнение без разделителей). Не нашлось — расшифровка самой зоны. Зоны нет — по отчеству.
     */
    private static function name(array $lines, ?array $mrz, array &$checked): ?string
    {
        $words = [];
        foreach ($lines as $line) {
            foreach (preg_split('/\s+/u', self::cyr(mb_strtoupper($line), true)) as $w) {
                $w = trim($w, '.,:;');
                if (preg_match('/^[А-ЯЁ-]{2,}$/u', $w) && ! preg_match(self::LABELS, $w)) {
                    $words[] = $w;
                }
            }
        }
        $words = array_values(array_unique($words));
        $body = $mrz['mrz_body'] ?? null;
        if ($body !== null) {
            $target = str_replace('<', '', $body);
            $best = null;
            $bestDist = 99;
            $codes = array_map(fn ($w) => self::toMrz($w), $words);
            $n = count($words);
            for ($i = 0; $i < $n; $i++) {
                if (! str_starts_with($target, substr($codes[$i], 0, 2))) {
                    continue;
                }
                for ($j = 0; $j < $n; $j++) {
                    for ($k = 0; $k < $n; $k++) {
                        if ($i === $j || $j === $k || $i === $k) {
                            continue;
                        }
                        $dist = levenshtein($codes[$i].$codes[$j].$codes[$k], $target);
                        if ($dist < $bestDist) {
                            [$best, $bestDist] = [[$words[$i], $words[$j], $words[$k]], $dist];
                        }
                    }
                }
            }
            // Сама зона: фамилия до «<<», имя и отчество через «<»; одиночный «<» потерян — отчество по слову разворота.
            $parts = preg_split('/<<+/', $body, 2);
            $rest = array_values(array_filter(preg_split('/<+/', $parts[1] ?? '')));
            if (count($rest) === 1) {
                $rest = self::splitRest($rest[0], $codes);
            }
            $zone = array_values(array_filter([$parts[0] ?? '', ...array_slice($rest, 0, 2)]));
            if ($best && $bestDist <= max(1, intdiv(strlen($target), 12))) {
                $bestDist === 0 && $checked[] = 'name';
                // Разворот и зона расходятся в букве (Р → П, В → Б на разворотах, < → K в зоне): имя и отчество —
                // по словарю имён, фамилия — по зоне, если слово той же длины.
                if ($bestDist > 0 && count($zone) === 3) {
                    foreach ($best as $i => $w) {
                        $z = self::fromMrz($zone[$i]);
                        if ($z === $w || $z === '') {
                            continue;
                        }
                        $best[$i] = match ($i) {
                            1 => self::isName($w) ? $w : (self::isName($z) ? $z : $w),
                            2 => self::isName(self::patronymicStem($w)) ? $w : (self::isName(self::patronymicStem($z)) ? $z : $w),
                            // Фамилия — по зоне: латиница зоны читается ровнее, а на развороте теряются буквы («ЮДНА»).
                            default => levenshtein(self::toMrz($w), $zone[0]) <= 2 ? $z : $w,
                        };
                    }
                }

                return self::title(implode(' ', $best));
            }
            // Слов разворота нет — расшифровка зоны.
            if (count($zone) >= 2) {
                return self::title(implode(' ', array_map(fn ($c) => self::fromMrz($c), $zone)));
            }
        }
        // Без зоны: отчество по окончанию, перед ним имя и фамилия.
        foreach ($words as $i => $w) {
            if (preg_match('/(ВИЧ|ВНА|ИЧНА|ЧНА|ОГЛЫ|КЫЗЫ)$/u', $w) && $i >= 2) {
                return self::title($words[$i - 2].' '.$words[$i - 1].' '.$w);
            }
        }

        return null;
    }

    /** Есть ли имя в словаре частых имён (`resources/data/first-names.txt`). */
    private static function isName(string $w): bool
    {
        static $names = null;
        $names ??= array_flip(array_filter(array_map('trim', @file(__DIR__.'/../../../resources/data/first-names.txt') ?: [])));

        return $w !== '' && isset($names[str_replace('Ё', 'Е', $w)]) || isset($names[$w]);
    }

    /** «ВИКТОРОВИЧ» → «ВИКТОР», «ЮРЬЕВНА» → «ЮРИЙ»: имя отца для проверки по словарю. */
    private static function patronymicStem(string $w): string
    {
        $stem = preg_replace('/(ОВИЧ|ЕВИЧ|ОВНА|ЕВНА|ИЧНА|ИНИЧНА|ИЧ|ВНА)$/u', '', $w);

        return preg_match('/Ь$/u', $stem) ? mb_substr($stem, 0, -1).'ИЙ' : (preg_match('/[ЕО]$/u', $w) ? $stem : $stem);
    }

    /** «IVANMIHAILOVI3» → ['IVAN', 'MIHAILOVI3']: по слову разворота в конце, иначе по окончанию отчества. */
    private static function splitRest(string $token, array $codes): array
    {
        foreach ($codes as $c) {
            if (strlen($c) >= 4 && strlen($c) < strlen($token) - 1 && str_ends_with($token, $c)) {
                return [substr($token, 0, -strlen($c)), $c];
            }
        }
        if (preg_match('/^(.{2,}?)([A-Z0-9]{3,}(?:OVI3|EVI3|OVNA|EVNA|I3NA|I3))$/', $token, $m)) {
            return [$m[1], $m[2]];
        }

        return [$token];
    }

    /** Кем выдан: заглавные ячейки верхней страницы до первой даты (это дата выдачи); куски серии («21 16») — мимо. */
    private static function issuer(array $lines): ?string
    {
        $out = [];
        foreach ($lines as $line) {
            // Дата выдачи или код подразделения — конец «кем выдан» (дата бывает не прочитана, код — почти всегда есть).
            if (self::dates($line) || preg_match('/(?<!\d)\d{3}\s*[-–—]\s*\d{3}(?!\d)|Код|подраздел/u', $line)) {
                break;
            }
            foreach (self::values($line) as $v) {
                if (preg_match('/РОССИЙСКАЯ\s*ФЕДЕРАЦИЯ$|^ПАСПОРТ/u', $v) || ! preg_match('/[А-Я]{2,}/u', $v)) {
                    continue;
                }
                // Шапка бланка «РОССИЙСКАЯ ФЕДЕРАЦИЯ» — и прочитанная с ошибкой («ФЕАЕРАЦИЯ»).
                $v = implode(' ', array_filter(preg_split('/\s+/u', $v), fn ($w) => min(levenshtein($w, 'РОССИЙСКАЯ'), levenshtein($w, 'ФЕДЕРАЦИЯ'), levenshtein($w, 'РОССИЙСКАЯФЕДЕРАЦИЯ')) > 4));
                // Одиночная буква в начале «кем выдан» — сор с герба или подписи («Я ГУ МВД»); предлог «В ГОР.» — значение.
                $out === [] && $v = preg_replace('/^[А-ЯЁ](?<![ВСКОУ])\s+(?=[А-ЯЁ]{2})/u', '', $v);
                $v = trim(preg_replace(['/\s{2,}/u', '/\s+\d{2}\s\d{2}$/u'], [' ', ''], $v));
                $v !== '' && $out[] = $v;
            }
        }

        return $out ? implode(' ', $out) : null;
    }

    /** Место рождения: заглавные ячейки после строки с датой рождения (последняя дата), до зоны. */
    private static function birthPlace(array $lines): ?string
    {
        $start = null;
        foreach ($lines as $i => $line) {
            if (self::dates($line) || preg_match('/\b(МУЖ|ЖЕН)\b/u', self::cyr(mb_strtoupper($line)))) {
                $start = $i;
            }
        }
        if ($start === null) {
            return null;
        }
        $out = [];
        foreach (array_slice($lines, $start) as $n => $line) {
            if (str_contains($line, '<') || preg_match('/P[NH]R[UJ]S|\d{9}/', $line)) {
                break;
            }
            // Строка с датой рождения и полом — из неё только то, что не дата и не пол (первая строка места бывает рядом).
            if ($n === 0) {
                $line = preg_replace('/\d{2}[.,]\d{2}[.,]\d{4}|\b(МУЖ|ЖЕН)\.?/u', '   ', self::cyr(mb_strtoupper($line)));
            }
            foreach (self::values($line) as $v) {
                if (! preg_match('/[А-Я]{2,}/u', $v) || preg_match('/^(РОССИЯ|RUSSIA|POCCИЯ)(\s|$)|^\d/u', $v) || preg_match(self::LABELS, $v)) {
                    continue;
                }
                // Хвост подписи «рождения» («ИЯ») перед «ГОР.», «С.», «ПОС.» — не значение.
                $out[] = preg_replace(['/\s{2,}/u', '/^[А-ЯЁ]{1,2}\s+(?=(ГОР|ПОС|С|Д|Г|ДЕР|СТ|РП|ПГТ)\.?\s)/u'], [' ', ''], $v);
            }
        }

        return $out ? implode(' ', $out) : null;
    }

    /** @return list<string> даты текста в Y-m-d по порядку */
    private static function dates(string $text): array
    {
        preg_match_all('/(?<!\d)(\d{2})[.,](\d{2})[.,](\d{4})(?!\d)/u', preg_replace('/[OО]/u', '0', $text), $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $d) {
            if ((int) $d[1] >= 1 && (int) $d[1] <= 31 && (int) $d[2] >= 1 && (int) $d[2] <= 12 && (int) $d[3] > 1900 && (int) $d[3] < 2100) {
                $out[] = "{$d[3]}-{$d[2]}-{$d[1]}";
            }
        }

        return $out;
    }

    private static function yymmdd(string $s, bool $birth): string
    {
        $yy = (int) substr($s, 0, 2);
        $year = $yy + ($birth ? ($yy > (int) date('y') ? 1900 : 2000) : 2000);

        return sprintf('%04d-%s-%s', $year, substr($s, 2, 2), substr($s, 4, 2));
    }

    /** Контрольная цифра ICAO: веса 7-3-1, «<» — 0, буквы — 10…35. */
    private static function check(string $s): string
    {
        $w = [7, 3, 1];
        $sum = 0;
        foreach (str_split($s) as $i => $c) {
            $v = $c === '<' ? 0 : (ctype_digit($c) ? (int) $c : ord($c) - 55);
            $sum += $v * $w[$i % 3];
        }

        return (string) ($sum % 10);
    }

    /** Цифровые места строки 2: похожие буквы — цифры (O → 0, I → 1…), кроме RUS и пола. */
    private static function digits(string $l): string
    {
        $map = ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'B' => '8', 'G' => '6', 'T' => '7'];
        if (! preg_match('/^(.*?)(RUS|RU5|RJS)(.*)$/', $l, $m)) {
            return $l;
        }
        $before = strtr($m[1], $map);
        // После RUS: рождение и контрольная (7 цифр), пол, дальше снова цифры.
        $after = $m[3];
        $head = strtr(substr($after, 0, 7), $map);
        $sex = substr($after, 7, 1);
        $tail = strtr(substr($after, 8), $map + ['K' => '<']);

        return $before.'RUS'.$head.$sex.$tail;
    }

    private static function toMrz(string $word): string
    {
        $flip = array_flip(self::MRZ_RU);
        $out = '';
        foreach (mb_str_split(str_replace('Ё', 'Е', $word)) as $ch) {
            $out .= $ch === '-' ? '<' : ($flip[$ch] ?? '');
        }

        return $out;
    }

    private static function fromMrz(string $code): string
    {
        $out = '';
        foreach (str_split($code) as $ch) {
            $out .= self::MRZ_RU[$ch] ?? '';
        }

        return $out;
    }

    /**
     * Латинские двойники в кириллических строках — кириллицей. $all — и слова целиком из двойников («TATAPCTAH»,
     * «KB.»): в кириллических местах паспорта (кем выдан, адрес) латиницы не бывает.
     */
    private static function cyr(string $s, bool $all = false): string
    {
        return preg_replace_callback('/\S+/u', function ($m) use ($all) {
            $w = $m[0];
            $mixed = preg_match('/[А-ЯЁ]/u', $w) && ! preg_match('/[^A-ZА-ЯЁ0-9.\-:\/]/u', $w);
            // Только из двойников (и G, V, S — их OCR ставит вместо Г, В, С): «TATAPCTAH», «KB.», «C.», «46A».
            $latin = $all && preg_match('/^[ABCEHKMOPTXYGVS0-9.\-:\/]+$/', $w) && preg_match('/[A-Z]{2,}|^[A-Z]\.$|^\d+[A-Z]$/', $w);
            if (! $mixed && ! $latin) {
                return $w;
            }

            $w = strtr($w, array_diff_key(self::LOOKALIKE, ['3' => 1, '0' => 1]) + ['G' => 'Г', 'V' => 'В', 'S' => 'С', 'N' => 'Н', 'R' => 'Р']);

            // 3 и 0 — буквы З и О только между буквами («ЗАРЕГИСТРИРОВАН»), в числах («КВ. 23») — цифры.
            return preg_replace(['/(?<=[А-ЯЁ])3|3(?=[А-ЯЁ])/u', '/(?<=[А-ЯЁ])0|0(?=[А-ЯЁ])/u'], ['З', 'О'], $w);
        }, $s);
    }

    /** Ячейки строки OCR (через три пробела) без подписей бланка: подпись мелким шрифтом — строчными, значение — заглавными. */
    private static function values(string $line): array
    {
        $out = [];
        foreach (preg_split('/\s{3,}/u', trim($line)) as $cell) {
            $cell = trim($cell);
            $up = mb_strtoupper($cell);
            if ($cell === '' || $cell !== $up && preg_match('/\p{Ll}{2,}/u', $cell)) {
                continue;
            }
            $out[] = self::fixPrefix(self::cyr($up, true));
        }

        return $out;
    }

    /** Частые ошибки OCR в сокращениях: «ФОР.» и «TOP.» — «ГОР.». */
    private static function fixPrefix(string $s): string
    {
        $s = preg_replace(['/(^|\s)(?:[ФТF]О[РП]|F[OО][PР]|ГОП)\./u', '/(^|\s)5-Р(?=\s)/u'], ['$1ГОР.', '$1Б-Р'], $s);

        // «ГОР.МОСКВА» — сокращение и слово через пробел.
        return preg_replace('/(^|\s)(ГОР|УЛ|ПОС|С|Д|КВ|Г|ПР-КТ|Б-Р)\.(?=[А-ЯЁ])/u', '$1$2. ', $s);
    }

    /** Кириллические двойники в строке зоны — латиницей. */
    private static function latin(string $s): string
    {
        return strtr($s, ['А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H', 'К' => 'K', 'М' => 'M', 'О' => 'O', 'Р' => 'P', 'Т' => 'T', 'Х' => 'X', 'У' => 'Y', '«' => '<', '‹' => '<']);
    }

    private static function title(string $s): string
    {
        return mb_convert_case(mb_strtolower($s), MB_CASE_TITLE);
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', str_replace("\u{00A0}", ' ', $text))), fn ($l) => $l !== ''));
    }
}
