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
    /** Греческие двойники кириллицы, что ставит OCR (13.11.2026: место рождения «РΦ ГОР. МОСКВА» у Мишина). */
    private const GREEK = ['Α' => 'А', 'Β' => 'В', 'Γ' => 'Г', 'Ε' => 'Е', 'Η' => 'Н', 'Κ' => 'К', 'Λ' => 'Л', 'Μ' => 'М', 'Ν' => 'Н',
        'Ο' => 'О', 'Π' => 'П', 'Ρ' => 'Р', 'Τ' => 'Т', 'Υ' => 'У', 'Φ' => 'Ф', 'Χ' => 'Х', 'ο' => 'о', 'α' => 'а', 'ε' => 'е'];

    /** Месяцы словом в штампе прописки: «23 АПРЕЛЯ 2004Г.». */
    private const MONTHS = ['ЯНВАР' => 1, 'ФЕВРАЛ' => 2, 'МАРТ' => 3, 'АПРЕЛ' => 4, 'МА[ЯЙ]' => 5, 'ИЮН' => 6, 'ИЮЛ' => 7, 'АВГУСТ' => 8,
        'СЕНТЯБР' => 9, 'ОКТЯБР' => 10, 'НОЯБР' => 11, 'ДЕКАБР' => 12];

    public static function main(string $text): array
    {
        $text = strtr($text, self::GREEK);
        $lines = self::lines(explode(self::ACROSS, $text, 2)[0]);
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
        // Серия и номер красным вертикально у обеих страниц: «87 11 524350». Без зоны верим, только если сошлись —
        // неверный номер в договоре хуже пустого поля.
        if ($number = self::number($text, $fields['passport_code'] ?? null, $fields['passport_issued_at'] ?? null)) {
            $fields['passport'] = $number;
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
            $fields['birth_place'] = self::cleanPlace($place, $fields['name'] ?? null, $fields['passport'] ?? null);
        }
        if (($fields['birth_place'] ?? null) === '') {
            unset($fields['birth_place']);
        }
        self::checkDates($fields, $checked);

        // Заглавные бланка — словами (17.11.2026, владелец, `PassportCase`); «кем выдан» — заглавными, как в бланке.
        isset($fields['birth_place']) && $fields['birth_place'] = PassportCase::format($fields['birth_place']);
        isset($fields['passport_issued']) && $fields['passport_issued'] = mb_strtoupper($fields['passport_issued']);
        // Серия без пробела: «8207 445349» (07.11.2026, владелец).
        isset($fields['passport']) && $fields['passport'] = preg_replace('/^(\d{2})\s(\d{2})\s/u', '$1$2 ', $fields['passport']);

        return ['fields' => $fields, 'checked' => array_values(array_unique($checked))];
    }

    /**
     * Код субъекта в коде подразделения (первые две цифры, «050-003» — Дагестан) → первые две цифры серии паспорта (код
     * ОКАТО, «82 07»). Неуверенные субъекты (автономные округа, Крым) не внесены: без них проверка просто не помогает.
     */
    private const OKATO = [
        '01' => '79', '02' => '80', '03' => '81', '04' => '84', '05' => '82', '06' => '26', '07' => '83', '08' => '85', '09' => '91',
        '10' => '86', '11' => '87', '12' => '88', '13' => '89', '14' => '98', '15' => '90', '16' => '92', '17' => '93', '18' => '94',
        '19' => '95', '20' => '96', '21' => '97', '22' => '01', '23' => '03', '24' => '04', '25' => '05', '26' => '07', '27' => '08',
        '28' => '10', '29' => '11', '30' => '12', '31' => '14', '32' => '15', '33' => '17', '34' => '18', '35' => '19', '36' => '20',
        '37' => '24', '38' => '25', '39' => '27', '40' => '29', '41' => '30', '42' => '32', '43' => '33', '44' => '34', '45' => '37',
        '46' => '38', '47' => '41', '48' => '42', '49' => '44', '50' => '46', '51' => '47', '52' => '22', '53' => '49', '54' => '50',
        '55' => '52', '56' => '53', '57' => '54', '58' => '56', '59' => '57', '60' => '58', '61' => '60', '62' => '61', '63' => '36',
        '64' => '63', '65' => '64', '66' => '65', '67' => '66', '68' => '68', '69' => '28', '70' => '69', '71' => '70', '72' => '71',
        '73' => '73', '74' => '75', '75' => '76', '76' => '78', '77' => '45', '78' => '40', '79' => '99',
    ];

    /** Строка, после которой `ocr` кладёт цифры, прочитанные поперёк страницы (серия и номер стоят вертикально). */
    public const ACROSS = '‹поперёк›';

    /**
     * Серия и номер (07.11.2026, скан Максутова: красные цифры у края обрезаны — сверху «07 445349», снизу «82 07 4453»).
     * Номер — шесть цифр, встреченные не меньше двух раз. Серия — четыре цифры перед номером или перед его началом;
     * верна, если её подтверждают два отпечатка (целиком или последние две цифры перед номером) или один, но первые две
     * цифры — ОКАТО субъекта из кода подразделения, а год бланка не позже выдачи. Иначе пусто.
     */
    private static function number(string $text, ?string $code, ?string $issuedAt): ?string
    {
        $text = preg_replace('/[OО]/u', '0', $text);
        // Прочитанное поперёк — отдельно: те же цифры, прочитанные и вдоль, считались бы двумя отпечатками. Номер —
        // по тому из двух, где он встречен чаще (поперёк обрезанный отпечаток «82 07 4453» — тоже встреча).
        $parts = explode(self::ACROSS, $text, 2);
        $across = isset($parts[1]) && preg_match('/\d{4}/', $parts[1]) ? $parts[1] : null;
        $text = $across ?? $parts[0];

        preg_match_all('/(?<!\d)(\d{2})\s?(\d{2})\s{0,3}(\d{6})(?!\d)/u', $text, $full, PREG_SET_ORDER);
        $whole = array_count_values(array_map(fn ($m) => "{$m[1]} {$m[2]} {$m[3]}", $full));
        arsort($whole);
        if ($whole && reset($whole) >= 2) {
            return array_key_first($whole);
        }

        $six = fn (string $t) => preg_match_all('/(?<!\d)\d{6}(?!\d)|(?<=\d{4})\d{6}(?!\d)/u', $t, $x) ? $x[0] : [];
        $counts = [];
        foreach (array_filter([$parts[0], $across]) as $source) {
            $here = array_count_values($six($source));
            foreach (array_keys($here) as $n) {
                // Обрезанный отпечаток: серия и начало номера, не меньше четырёх цифр.
                preg_match_all('/(?<!\d)\d{2}\s?\d{2}\s{0,3}(\d{4,5})(?!\d)/u', $source, $cut);
                $here[$n] += count(array_filter($cut[1], fn ($c) => str_starts_with((string) $n, $c)));
                $counts[$n] = max($counts[$n] ?? 0, $here[$n]);
            }
        }
        arsort($counts);
        $number = $counts && reset($counts) >= 2 ? (string) array_key_first($counts) : null;
        if ($number === null) {
            return null;
        }
        // Серия перед номером или перед его началом (низ обрезан: «82 07 4453»), и одиночные две цифры перед номером
        // («07 445349» — верх обрезан).
        $series = [];
        $tails = [];
        preg_match_all('/(?<!\d)(\d{2})\s?(\d{2})\s{0,3}(\d{2,6})(?!\d)/u', $text, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            if (str_starts_with($number, $x[3])) {
                $series[] = $x[1].' '.$x[2];
            }
        }
        preg_match_all('/(?<!\d)(\d{2})\s{1,3}'.$number.'(?!\d)/u', $text, $t);
        foreach ($t[1] as $two) {
            $tails[] = $two;
        }
        $best = null;
        $bestScore = 0;
        foreach (array_unique($series) as $s) {
            $score = count(array_keys($series, $s)) + count(array_keys($tails, substr($s, 3)));
            // «82 07 445349» целиком дал и серию, и хвост «07» — это один отпечаток.
            $score -= count(array_filter($full, fn ($f) => "{$f[1]} {$f[2]}" === $s && $f[3] === $number));
            if ($score > $bestScore) {
                [$best, $bestScore] = [$s, $score];
            }
        }
        if ($best === null) {
            return null;
        }
        $region = $code ? (self::OKATO[substr($code, 0, 2)] ?? null) : null;
        $blank = (int) substr($best, 3);
        $year = $issuedAt ? (int) substr($issuedAt, 2, 2) : null;
        $plausible = $region === substr($best, 0, 2) && ($year === null || $blank <= $year || $blank >= 97);
        if ($bestScore >= 2 || ($bestScore >= 1 && $plausible)) {
            return $best.' '.$number;
        }

        return null;
    }

    /**
     * Адрес из штампа «Зарегистрирован»: последний по дате, «снят с учёта» — мимо.
     *
     * @return array{fields: array<string, string>, checked: list<string>, stamped?: bool}
     */
    public static function registration(string $text): array
    {
        $text = strtr($text, self::GREEK);
        $lines = self::lines(explode(self::ACROSS, $text, 2)[0]);
        $stamps = [];
        $current = null;
        // Строки до штампа: у сельского штампа адрес стоит над «ЗАРЕГИСТРИРОВАН» (07.11.2026, «РЕСПУБЛИКА ДАГЕСТАН /
        // КАРАБУДАХКЕНТСКИЙ РАЙОН / АДМИНИСТРАЦИЯ С.АДАНАК / ЗАРЕГИСТРИРОВАН / 0904 2008.»).
        $loose = [];
        foreach ($lines as $line) {
            $up = self::cyr(mb_strtoupper($line));
            if (preg_match('/З?АРЕГИСТР|РЕГИСТРИРОВАН/u', $up) && ! preg_match('/СНЯТ|РЕГИСТРАЦИОННОГО/u', $up)) {
                $current !== null && $stamps[] = $current;
                $current = ['date' => null, 'lines' => [], 'before' => $loose];
                $loose = [];

                continue;
            }
            if (preg_match('/СНЯТ\b|С РЕГИСТРАЦИОННОГО/u', $up)) {
                $current !== null && $stamps[] = $current;
                $current = null;
                $loose = [];

                continue;
            }
            if ($current === null) {
                $loose[] = $line;

                continue;
            }
            if (! $current['date'] && ($d = self::stampDate($line))) {
                $current['date'] = $d;

                continue;
            }
            // Дата словами в начале строки адреса или с нечитаемым годом (17.11.2026: «23 ОКТЯБРЯ2 2000, РЕР-Н: РЕСП ДАГЕСТАН…»,
            // «20 НОЯБРЯ 200Г Г.») — дата штампа, не адрес.
            [$line, $d] = self::leadingWordDate($line);
            $d && ! $current['date'] && $current['date'] = $d;
            $line = self::screenNoise($line);
            if ($line === '') {
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
        if ($address === '') {
            // Над словом — не больше пяти строк вплотную к нему, без заголовка страницы «Место жительства».
            $before = array_values(array_filter($stamps[0]['before'], fn ($l) => ! preg_match('/МЕСТО|ЖИТЕ.?.?ЬСТВ|^\W*\d{1,2}\W*$/u', self::cyr(mb_strtoupper($l), true))));
            $address = self::address(array_slice($before, -5));
        }

        // `stamped` — страница прописки и без читаемого адреса (рукопись): по нему `ReadPassport::placePage` ставит страницу на место.
        return ['fields' => $address !== '' ? ['reg_address' => PassportCase::format($address)] : [], 'checked' => [], 'stamped' => true];
    }

    /** Дата штампа — и без точек: «09.04.2008», «0904 2008.», «09 04 2008». */
    private static function stampDate(string $line): ?string
    {
        if ($d = self::dates($line)) {
            return $d[0];
        }
        if (preg_match('/(?<!\d)(\d{2})[.,\s]?(\d{2})[.,\s]{1,4}((?:19|20)\d{2})(?!\d)/u', preg_replace('/[OО]/u', '0', $line), $m)
            && (int) $m[1] >= 1 && (int) $m[1] <= 31 && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        // Месяц словом (13.11.2026: «23 АПРЕЛЯ 2004Г.» вставало в адрес) — только строка-дата, без адреса рядом.
        $up = self::cyr(mb_strtoupper($line));
        foreach (self::MONTHS as $word => $month) {
            if (preg_match('/(?<!\d)(\d{1,2})\s*[«"]?\s*'.$word.'\p{L}*\s*((?:19|20)\d{2})/u', $up, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 31
                && mb_strlen(preg_replace('/[^А-ЯЁA-Z]/u', '', str_replace($m[0], '', $up))) <= 3) {
                return sprintf('%s-%02d-%02d', $m[2], $month, (int) $m[1]);
            }
        }

        return null;
    }

    /**
     * Дата месяцем словом в начале строки: [строка без неё, дата или null]. Год прочитан с ошибкой («200Г») — дата уходит
     * из строки, но не ставится.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function leadingWordDate(string $line): array
    {
        $up = self::cyr(mb_strtoupper($line));
        foreach (self::MONTHS as $word => $month) {
            if (preg_match('/^\s*(\d{1,2})\s*[«"]?\s*'.$word.'\p{L}*\d?\s*((?:19|20)[\dОOГ]{2})\s*(?:Г\.?)?\s*[,.]?\s*/u', $up, $m)) {
                $year = preg_match('/^\d{4}$/', $m[2]) ? $m[2] : null;
                $date = $year && (int) $m[1] >= 1 && (int) $m[1] <= 31 ? sprintf('%s-%02d-%02d', $year, $month, (int) $m[1]) : null;

                return [trim(mb_substr($up, mb_strlen($m[0]))), $date];
            }
        }

        return [$line, null];
    }

    /** Фото экрана телефона (17.11.2026, Минакова): «3 сентября, 10:53», «7 10:37» — время сообщения и строка состояния, не адрес. */
    private static function screenNoise(string $line): string
    {
        $line = preg_replace('/\d{1,2}\s+(?:'.implode('|', array_keys(self::MONTHS)).')\p{L}*,?\s+\d{1,2}:\d{2}/iu', ' ', $line);

        return trim(preg_replace(['/(?<![\d.:])\d{1,2}:\d{2}(?![\d:])/u', '/\s{2,}/u'], [' ', '   '], $line));
    }

    /** Строки штампа → «САМАРСКАЯ ОБЛ., ГОР. САМАРА, УЛ. МОЛОДОГВАРДЕЙСКАЯ, Д. 151, КВ. 23». */
    private static function address(array $lines): string
    {
        $parts = [];
        foreach ($lines as $line) {
            // «АДМИНИСТРАЦИЯ С.АДАНАК» — кто ставил штамп; адрес — село.
            $up = preg_replace('/^АДМИНИСТРАЦИ[ЯИ]\s+/u', '', self::fixPrefix(self::cyr(mb_strtoupper(trim($line)), true)));
            // Кем зарегистрирован, код подразделения, подпись — конец адреса («ГУМВД», «ФУМВД» — тот же «ГУ МВД»).
            if (preg_match('/МВД|УФМС|ОВД|ОВМ|ОТДЕЛ|МИГРАЦ|ПАСПОРТН/u', $up) || preg_match('/^\d{3}-\d{3}|ЗАВЕРИЛ|ПОДПИСЬ/u', $up)) {
                break;
            }
            // Тот же штамп «кем зарегистрирован», прочитанный с ошибками (17.11.2026, паспорта прода: «ОТДЕЕ ПО БОНРОСАН НИГРАЦИИ»,
            // «ПО ВОПРОСАН ИКГРАДИЕ ОТДЕНА ПОНИЦИИ», «УПРАВНЕННЯ ИВ РОССИИ», «"ОВК НО ИВД», «(НАНМЕНОВАНИЕ ПОДРАЗДЕЛЕНИЯ») — конец адреса.
            if (preg_match('/ВОПРОС|[ВБ]О[НП]РОСА|[МН]ИГРАЦ|ИНГРАЦ|ИКГРАД|ИГРАНИИ|ПО[ЛН]И[ЦН]ИИ|ОТЛЕЛ|РОССИ(?!\p{L})|РОССИИ|ССИИ\b|[ЕО]НОВАН|ПОДРАЗДЕ|(?<!\p{L})ОТДЕ(?:НА|Е)?(?!\p{L})|(?<!\p{L})\p{L}{0,2}ВД(?!\p{L})/u', $up)) {
                break;
            }
            // Подпись поля, прочитанная с ошибкой («УННЦА:», «УЛИЩА:», «ПУНКГ:», «РЕР-Н:», «РАЙОВ:»), и «РЕГ-» без «Н» — мимо; «Дом:»,
            // «Кв.:», «Корп.:» разбираются ниже.
            $up = preg_replace_callback('/^(?:[А-ЯЁA-Z.\-]{1,7}\s*:\s*)+/u', fn ($m) => preg_match('/^(ДО|КВ|КО)/u', $m[0]) ? $m[0] : '', $up);
            $up = preg_replace(['/^РЕ[ГР]-\s*/u', '/(^|\s)(?:УН|YN|УЛ)(?=\s|$)/u', '/(^|\s)[ОO][ВБ]Л\./u', '/^А\.(?=\d)/u', '/(\d)КВ\.?(\d)/u'],
                ['', '$1УЛ.', '$1ОБЛ.', 'Д.', '$1 КВ. $2'], $up);
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

        // Рукопись OCR читает латиницей («16 IHEBPRAHE DODY», «WINP», 16.11.2026, Файзулаев): в адресе штампа латиницы
        // не бывает — такой кусок не адрес; без русского слова адреса нет вовсе (мусор хуже пустого поля).
        $parts = array_filter($parts, fn ($p) => $p !== '' && ! (preg_match('/[A-Z]/', $p) && ! preg_match('/[А-ЯЁ]/u', $p)));

        // Мусор вместо адреса (17.11.2026: «1ОКОРП», «А0MN12 КОРП. 19», «РАЙОЕ PERNON» — рукопись) хуже пустого поля: нужно
        // слово от пяти букв и либо пометка места (г., ул., обл., край, р-н…), либо хотя бы три слова.
        $text = implode(' ', $parts);
        $marker = preg_match('/(^|[\s,.])(Г|ГОР|УЛ|ОБЛ|ОБЛАСТЬ|КРАЙ|РЕСП|РЕСПУБЛИКА|С|Д|П|ПОС|ПГТ|РП|ПЕР|ПР-Т|ПР-КТ|Р-Н|РАЙОН|МКР|ПЛ|Ш|НАБ|ТЕР|СНТ|ДНТ)(?=[\s.,]|$)/u', $text);
        $real = preg_match('/[А-ЯЁ]{5,}/u', $text) && ($marker || count(preg_split('/[\s,]+/u', trim($text))) >= 3);

        return $real ? implode(', ', $parts) : '';
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
            foreach (preg_split('/\s+/u', $line) as $raw) {
                // Подпись бланка мелким шрифтом — строчными, значение — заглавными (16.11.2026, Файзулаев: подпись
                // «Отчество», прочитанная «Отчестае», встала на место имени).
                if (preg_match('/\p{Ll}.*\p{Ll}/u', $raw)) {
                    continue;
                }
                $w = trim(self::cyr(mb_strtoupper($raw), true), '.,:;');
                if (preg_match('/^[А-ЯЁ-]{2,}$/u', $w) && ! preg_match(self::LABELS, $w) && ! self::nearLabel($w)) {
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
                        // Б и В разворот путает («БИТАЛИЙ БИКТОРОВИЧ» при «VITALIO VIKTOROVI3» в зоне, 17.11.2026, Зайцев) — не ошибка.
                        $dist = levenshtein(strtr($codes[$i].$codes[$j].$codes[$k], 'B', 'V'), strtr($target, 'B', 'V'));
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

                return self::title(implode(' ', self::fixLetters($best, $target)));
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

    /**
     * Буква, что разворот путает в имени и отчестве (Б/В, П/Р, Н/И, Т/Г): имени нет в словаре, а с заменой есть и зона внизу
     * не против — заменить; без зоны не трогаем (ревью 17.11.2026: редкое имя не по словарю подменилось бы соседним)
     * («БИТАЛИЙ БИКТОРОВИЧ» → «ВИТАЛИЙ ВИКТОРОВИЧ», 17.11.2026). Фамилию не трогаем: словаря фамилий нет.
     *
     * @param  list<string>  $w  фамилия, имя, отчество
     * @return list<string>
     */
    private static function fixLetters(array $w, string $target): array
    {
        $swaps = ['Б' => 'В', 'В' => 'Б', 'П' => 'Р', 'Р' => 'П', 'Н' => 'И', 'И' => 'Н', 'Т' => 'Г', 'Г' => 'Т', 'Ф' => 'Г'];
        foreach ([1, 2] as $i) {
            $ok = fn (string $x) => $i === 1 ? self::isName($x) : self::isName(self::patronymicStem($x));
            if (! isset($w[$i]) || $ok($w[$i])) {
                continue;
            }
            // Сначала все такие буквы разом («БИКТОРОВИЧ»), потом по одной («МАРТАРИТА» → «МАРГАРИТА», «САХИВ» → «САХИБ»).
            $letters = mb_str_split($w[$i]);
            $tries = [];
            foreach ($swaps as $from => $to) {
                str_contains($w[$i], $from) && $tries[] = str_replace($from, $to, $w[$i]);
            }
            foreach ($letters as $p => $c) {
                isset($swaps[$c]) && $tries[] = implode('', array_replace($letters, [$p => $swaps[$c]]));
            }
            // Замена — только если зона внизу с ней сходится лучше: редкое настоящее имя не по словарю не подменяется соседним.
            // Б и В зона не различает (сверка — без них): такая замена проходит по словарю, остальные — если зона сходится лучше.
            $bv = fn (string $x) => strtr($x, 'B', 'V');
            $dist = fn (array $x) => levenshtein($bv(implode('', array_map(fn ($p) => self::toMrz($p), $x))), $bv($target));
            $was = $dist($w);
            foreach ($tries as $v) {
                $same = $bv(self::toMrz($v)) === $bv(self::toMrz($w[$i]));
                if ($ok($v) && ($same || $dist(array_replace($w, [$i => $v])) < $was)) {
                    $w[$i] = $v;
                    break;
                }
            }
        }

        return $w;
    }

    /** Подпись «Фамилия» / «Отчество», прочитанная заглавными с ошибкой («ОТЧЕСТАЕ»). */
    private static function nearLabel(string $w): bool
    {
        if (mb_strlen($w) < 6) {
            return false;
        }
        foreach (['ФАМИЛИЯ', 'ОТЧЕСТВО', 'РОЖДЕНИЯ'] as $label) {
            if (levenshtein($w, $label) <= 4) { // байты: кириллическая буква — два
                return true;
            }
        }

        return false;
    }

    /** Есть ли имя в словаре частых имён (`resources/data/first-names.txt`). */
    public static function isName(string $w): bool
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

        return $out ? self::dropJunkBefore(implode(' ', $out)) : null;
    }

    /**
     * Сор перед названием органа (17.11.2026, по паспортам прода: «ХР ГУ МВД…», «НАОРТ ПЫ ГУ МВД…», «ЛЮБИМАЯ ОТДЕЛЕНИЕМ…» — подпись
     * чата со скриншота, «Ф ОТДЕЛОМ…»): одно-два слова до органа уходят, если это не часть названия — «ЦЕНТРАЛЬНЫМ ОВД»,
     * «ЗАВОЛЖСКИМ РОВД», «68 ОТДЕЛОМ», «МО УФМС» остаются.
     */
    private static function dropJunkBefore(string $s): string
    {
        $words = preg_split('/\s+/u', trim($s));
        $organ = '/^(ГУ|ГУМВД|МВД|УМВД|ОМВД|УВД|ГУВД|ОВД|РОВД|ТП|ОУФМС|УФМС|ОФМС|ОВМ|ГУВМ|УВМ|ОУВМ|ОТДЕЛ\p{L}*|ТЕРРИТОРИАЛЬН\p{L}*|ПАСПОРТН\p{L}*)$/u';
        foreach (array_slice($words, 0, 3) as $i => $w) {
            if (! preg_match($organ, $w)) {
                continue;
            }
            $junk = array_slice($words, 0, $i);
            // Часть названия: прилагательное в творительном («ЦЕНТРАЛЬНЫМ»), номер, сокращение органа («ОВМ ОМВД», «ГУВМ МВД», «ТО УФМС»).
            $keep = fn ($w) => preg_match('/(ИМ|ЫМ|ОМ|ЕМ)$|^\d|^№|^(МО|МП|ТП|ТО|ОП|ОМ|ОО|ОВМ|ГУВМ|УВМ|ОУВМ|ОФМС|ОУФМС|УФМС)$|(ВМ|ВД|МС)$/u', $w);

            return $junk && ! array_filter($junk, $keep) ? implode(' ', array_slice($words, $i)) : $s;
        }

        return $s;
    }

    /**
     * Сор в месте рождения (17.11.2026, паспорта прода): пол («МУЖ.», «МУХ.» — «Мух. г. Харьков»), номер паспорта («…Р-НА 566608…»),
     * слова ФИО и обрывки «РОССИЙСКАЯ ФЕДЕРАЦИЯ» с соседних ячеек («Валентиновна Екатермна Рвсвева Дераци»), «РОССИА» — «РОССИЯ».
     */
    private static function cleanPlace(string $place, ?string $name, ?string $passport): string
    {
        $names = array_filter(preg_split('/\s+/u', mb_strtoupper((string) $name)), fn ($w) => mb_strlen($w) >= 4);
        $digits = preg_replace('/\D/', '', (string) $passport);
        // По буквам, не по байтам: «ЕКАТЕРМНА» ≈ «ЕКАТЕРИНА» (1), «ГРУЗИНСКАЯ» и «РОСТОВСКАЯ» — не имя.
        $near = fn (string $a, string $b): bool => mb_strlen($b) >= 4 && abs(mb_strlen($a) - mb_strlen($b)) <= 2 && self::lev($a, $b) <= max(1, intdiv(mb_strlen($b), 4));
        $tokens = preg_split('/\s+/u', trim($place));
        $words = [];
        foreach ($tokens as $i => $w) {
            $bare = trim($w, '.,');
            if (preg_match('/^(МУЖ|МУХ|ЖЕН|МYЖ)$/u', $bare) || (preg_match('/^\d{5,}$/', $bare) && ($digits === '' || str_contains($digits, $bare)))) {
                continue;
            }
            // Слово ФИО или шапки бланка — только не название места после «Г.», «ГОР.», «С.» («Г. МОСКВА» у Москвина остаётся).
            $afterAbbr = $i > 0 && preg_match('/^[А-ЯЁ\-]{1,4}\.$/u', $tokens[$i - 1]);
            if (! $afterAbbr && mb_strlen($bare) >= 4 && preg_match('/^[А-ЯЁ]+$/u', $bare) && ! in_array($bare, ['РОССИЯ', 'РОССИИ'], true)) {
                if (preg_match('/ДЕРАЦ|ФЕДЕР|ОССИЙСК|(ВИЧ|ВНА)$/u', $bare)) {
                    continue;
                }
                foreach ($names as $n) {
                    if ($near($bare, $n)) {
                        continue 2;
                    }
                }
            }
            $words[] = $w;
        }

        return trim(preg_replace('/(^|\s)РОССИА(?=\s|$)/u', '$1РОССИЯ', implode(' ', $words)));
    }

    /**
     * Даты, которых не бывает (17.11.2026: рождение 26.04.2026, паспорт выдан в 12 лет): паспорт выдают с 14 лет. Непроверенная
     * зоной дата, что спорит с другой, не ставится — пустое поле человек заполнит, а неверное в договоре никто не заметит.
     *
     * @param  array<string, string>  $fields
     * @param  list<string>  $checked
     */
    private static function checkDates(array &$fields, array $checked): void
    {
        $birth = $fields['birth_at'] ?? null;
        $issued = $fields['passport_issued_at'] ?? null;
        $now = date('Y-m-d');
        if ($birth && ! in_array('birth_at', $checked, true) && ($birth > date('Y-m-d', strtotime('-14 years')) || $birth < '1900-01-01')) {
            unset($fields['birth_at']);
            $birth = null;
        }
        if ($issued && ! in_array('passport_issued_at', $checked, true) && ($issued > $now || $issued < '1997-01-01')) {
            unset($fields['passport_issued_at']);
            $issued = null;
        }
        // Спорят друг с другом: уходит непроверенная; обе непроверенные — дата рождения (её берём последней датой страницы, она чаще
        // чужая), дата выдачи у кода подразделения остаётся.
        if ($birth && $issued && $issued < date('Y-m-d', strtotime($birth.' +14 years'))) {
            $drop = ! in_array('birth_at', $checked, true) ? 'birth_at' : (in_array('passport_issued_at', $checked, true) ? null : 'passport_issued_at');
            $drop && $fields = array_diff_key($fields, [$drop => true]);
        }
    }

    /** Расстояние Левенштейна по буквам (встроенный считает байты: кириллица — по два). */
    private static function lev(string $a, string $b): int
    {
        $map = [];
        $enc = function (string $s) use (&$map): string {
            return implode('', array_map(function ($c) use (&$map) {
                return $map[$c] ??= chr(count($map) % 255 + 1);
            }, mb_str_split($s)));
        };

        return levenshtein($enc($a), $enc($b));
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
            // Регистр не трогаем: подпись «рождения» рядом строчными («МУЖ.раждеви», 16.11.2026) — не значение.
            if ($n === 0) {
                $line = preg_replace('/\d{2}[.,]\d{2}[.,]\d{4}|\b(МУЖ|ЖЕН)\.?/iu', '   ', self::cyr($line));
            }
            foreach (self::values($line) as $v) {
                // Обрывок подписи «Пол» в строке даты («ПО.МУЖ» → «ПО.») — не значение.
                if ($n === 0 && preg_match('/^[А-ЯЁ]{1,2}\.?$/u', $v)) {
                    continue;
                }
                if (! preg_match('/[А-Я]{2,}/u', $v) || preg_match('/^(РОССИЯ|RUSSIA|POCCИЯ)(\s|$)|^\d/u', $v) || preg_match(self::LABELS, $v)) {
                    continue;
                }
                // Хвост подписи «рождения» («ИЯ») перед «ГОР.», «С.», «ПОС.» — не значение.
                $out[] = preg_replace(['/\s{2,}/u', '/^[А-ЯЁ]{1,2}\.?\s+(?=(ГОР|ПОС|С|Д|Г|ДЕР|СТ|РП|ПГТ)\.?\s)/u'], [' ', ''], $v);
            }
        }

        // «г.» сканер читает латинской «r.» / «R.» (16.11.2026, Видов: «R. МОСКВА») — сокращение города назад кириллицей.
        return $out ? preg_replace('/^[rR]\.\s*/u', 'Г. ', implode(' ', $out)) : null;
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

            // J — «Л», прочитанная латиницей посреди русского слова («HИKOJAEB», «СТЕРjИТАМАК», «Jенинкент», 17.11.2026).
            $w = strtr($w, array_diff_key(self::LOOKALIKE, ['3' => 1, '0' => 1]) + ['G' => 'Г', 'V' => 'В', 'S' => 'С', 'N' => 'Н', 'R' => 'Р', 'J' => 'Л']);

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
