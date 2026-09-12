<?php

declare(strict_types=1);

namespace App\Cars\Vin;

/**
 * Разбор VIN по бесплатным справочникам.
 *
 * Порт скрипта из xcar.ru/vin-decoder: логика здесь, данные — в resources/vin.
 * Что реально вытаскивается: марка и страна по WMI, год по 10-му символу,
 * а модель, КПП и привод — там, где производитель опубликовал схему VDS
 * (Lada, УАЗ, Audi, VW, Porsche, Mazda, Toyota и ещё два десятка марок).
 * Для Chery, Hyundai российской сборки, BMW и прочих схем нет — эти поля
 * остаются пустыми, и это правильно: выдуманная модель хуже пустой.
 */
class VinDecoder
{
    public const LENGTH = 17;

    private const ALLOWED = 'ABCDEFGHJKLMNPRSTUVWXYZ0123456789';

    private const FORBIDDEN = ['I', 'O', 'Q'];

    private const WEIGHTS = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];

    private const TRANSLITERATION = [
        'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'F' => 6, 'G' => 7, 'H' => 8,
        'J' => 1, 'K' => 2, 'L' => 3, 'M' => 4, 'N' => 5, 'P' => 7, 'R' => 9,
        'S' => 2, 'T' => 3, 'U' => 4, 'V' => 5, 'W' => 6, 'X' => 7, 'Y' => 8, 'Z' => 9,
    ];

    private const DRIVE_HINTS = [
        '/\b(?:awd|4wd|4x4|4matic|4motion|quattro|xdrive|all[- ]wheel)\b/i' => 'awd',
        '/\b(?:fwd|front[- ]wheel)\b/i' => 'fwd',
        '/\b(?:rwd|rear[- ]wheel)\b/i' => 'rwd',
    ];

    private const TRANSMISSION_HINTS = [
        '/\b(?:dual[- ]clutch|dct|dsg|s[- ]tronic|amt|automated manual)\b/i' => 'dual_clutch',
        '/\b(?:cvt|continuously variable|multitronic)\b/i' => 'cvt',
        '/\bautomatic(?!\s*(?:belt|seat|restraint))\b|\btiptronic\b/i' => 'automatic',
        '/\bmanual(?!\s*(?:belt|seat|restraint))\b/i' => 'manual',
    ];

    public function __construct(private readonly VinTables $tables = new VinTables) {}

    /**
     * Приводит ввод к каноническому виду: убирает пробелы, дефисы и
     * подчёркивания, поднимает регистр. Всё прочее — уже не оформление.
     */
    public static function normalize(string $raw): string
    {
        return mb_strtoupper(preg_replace('/[\s\-_\x{2010}-\x{2015}]+/u', '', trim($raw)) ?? '');
    }

    public static function expectedCheckChar(string $vin): ?string
    {
        $total = 0;
        foreach (str_split($vin) as $index => $char) {
            $value = ctype_digit($char) ? (int) $char : (self::TRANSLITERATION[$char] ?? null);
            if ($value === null) {
                return null;
            }
            $total += $value * self::WEIGHTS[$index];
        }
        $remainder = $total % 11;

        return $remainder === 10 ? 'X' : (string) $remainder;
    }

    /** Семнадцать допустимых знаков — без разбора, для памяти и форм. */
    public static function looksValid(string $vin): bool
    {
        return strlen($vin) === self::LENGTH && strspn($vin, self::ALLOWED) === self::LENGTH;
    }

    public function decode(string $raw): VinResult
    {
        $vin = self::normalize($raw);
        $result = new VinResult($raw, $vin === '' ? null : $vin);

        if ($vin === '') {
            $result->addError('EMPTY', 'VIN не заполнен');

            return $result;
        }

        foreach (str_split($vin) as $index => $char) {
            if (in_array($char, self::FORBIDDEN, true)) {
                $result->addError('INVALID_CHARACTER', sprintf(
                    'позиция %d: «%s» — буквы I, O и Q в VIN запрещены стандартом',
                    $index + 1,
                    $char,
                ));
            } elseif (! str_contains(self::ALLOWED, $char)) {
                $result->addError('INVALID_CHARACTER', sprintf(
                    'позиция %d: «%s» — недопустимый символ',
                    $index + 1,
                    $char,
                ));
            }
        }

        if (mb_strlen($vin) !== self::LENGTH) {
            $result->addError('BAD_LENGTH', sprintf(
                'длина %d, а должно быть %d',
                mb_strlen($vin),
                self::LENGTH,
            ));
        }

        if ($result->errors !== []) {
            return $result;
        }

        $wmi = substr($vin, 0, 3);
        $scheme = $this->tables->schemeFor($wmi);

        $expected = self::expectedCheckChar($vin);
        $required = (bool) ($scheme['check_digit_required'] ?? false);
        if ($required && $expected !== $vin[8]) {
            $result->addError('BAD_CHECK_DIGIT', sprintf(
                'контрольный разряд не сходится: ожидался «%s», в номере «%s». '
                .'Для североамериканских VIN он обязателен',
                (string) $expected,
                $vin[8],
            ));

            return $result;
        }

        $result->valid = true;
        $result->put('serial', substr($vin, 11), 'iso', 'high');
        $result->put('plant_code', $vin[10], 'iso', 'high');

        $this->fillWmi($result, $wmi, $scheme);
        // Ford Europe держит год в 11-м знаке, остальные — по ISO в 10-м.
        $this->fillYear($result, $vin[($scheme['year_at'] ?? 10) - 1], $scheme);
        $this->fillBrandByYear($result, $scheme);
        $this->fillVds($result, $vin, $wmi, $scheme);
        $this->fillEngineSpecs($result);

        return $result;
    }

    private function fillWmi(VinResult $result, string $wmi, array $scheme): void
    {
        $entry = $this->tables->wmi()[$wmi] ?? null;
        if ($entry !== null) {
            $result->put('manufacturer', $entry['manufacturer'] ?? null, 'wmi', 'high');
            $result->put('brand', $entry['brand'] ?? null, 'wmi', 'medium');
        } else {
            $result->warnings[] = "WMI «{$wmi}» нет в справочнике — производитель неизвестен";
        }

        if (! empty($scheme['brand'])) {
            $result->put('brand', $scheme['brand'], 'scheme', 'high');
        }
        $result->put('country', $this->tables->countryFor($wmi), 'iso', 'high');
        $result->put('region', $this->tables->regionFor($wmi), 'iso', 'high');
        if (! empty($scheme['region_note'])) {
            $result->warnings[] = $scheme['region_note'];
        }
    }

    private function fillYear(VinResult $result, string $char, array $scheme): void
    {
        $limit = (int) date('Y') + 1;
        $candidates = array_values(array_filter(
            $this->tables->yearCodes()[$char] ?? [],
            static fn (int $year) => $year <= $limit,
        ));

        if ($candidates === []) {
            $result->warnings[] = "символ 10 «{$char}» не кодирует год по ISO — "
                .'год выпуска из VIN не определяется';

            return;
        }

        $year = max($candidates);
        $since = $scheme['year_reliable_since'] ?? null;
        $reliable = ($scheme['year_reliable'] ?? true) && ($since === null || $year >= $since);

        if (! $reliable) {
            $confidence = 'low';
            $result->warnings[] = 'у этого производителя 10-й символ не гарантирует год выпуска — '
                .'сверьте с ПТС'.($since ? " (по ISO он кодируется только с {$since} года)" : '');
        } else {
            $confidence = ($scheme['check_digit_required'] ?? false) ? 'high' : 'medium';
        }

        $result->put('year', $year, 'iso', $confidence);
    }

    private function fillBrandByYear(VinResult $result, array $scheme): void
    {
        $rules = $scheme['brand_by_year'] ?? null;
        $year = $result->get('year');
        // Ненадёжный год марку не выбирает: у Москвича на X7L знак года — мусор.
        if (! is_array($rules) || ! is_int($year) || $result->confidence('year') === 'low') {
            return;
        }

        foreach ($rules as [$since, $until, $brand]) {
            if ($year >= $since && $year <= $until) {
                $result->put('brand', $brand, 'scheme', 'high');

                return;
            }
        }
    }

    private function fillVds(VinResult $result, string $vin, string $wmi, array $scheme): void
    {
        $names = [];
        if (! empty($scheme['vds'])) {
            $names[] = $scheme['vds'];
        }
        if ($scheme['wikibooks_table'] ?? true) {
            $brand = $result->get('brand');
            $byBrand = null;
            if (is_string($brand)) {
                $key = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($brand));
                $byBrand = $this->tables->wikibooksBrands()[$key] ?? null;
            }
            $name = $this->tables->wikibooksIndex()[$wmi] ?? null;
            // Страницы книги и список WMI ведут разные люди: страница KIA
            // записала себе TMA, хотя это завод Hyundai. Расходится — берём
            // страницу самой марки: там этот WMI просто не перечислен.
            if ($name !== null && is_string($brand) && ! $this->sameBrand($this->tables->vdsTable($name)['_brand'] ?? '', $brand)) {
                $name = null;
            }
            $name ??= $byBrand;
            if ($name !== null) {
                $names[] = $name;
            }
        }

        $texts = [];
        foreach ($names as $name) {
            foreach ($this->decodeTable($vin, $name, $result) as $field => $entry) {
                $value = $entry['value'] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $confidence = $entry['confidence'] ?? 'high';
                if (in_array($field, ['model', 'body', 'transmission', 'engine', 'drive'], true)) {
                    $texts[] = $value.' '.($entry['note'] ?? '');
                }

                if ($field === 'model') {
                    if (! empty($entry['ambiguous'])) {
                        // Год не развёл варианты — модели мы не знаем. Список
                        // уходит в подсказку, поле формы остаётся пустым.
                        $result->put(
                            'model_candidates',
                            implode(', ', array_merge([$value], $entry['alternatives'] ?? [])),
                            'vds',
                            'low',
                        );

                        continue;
                    }
                    $result->put('model', $value, 'vds', $confidence);
                    if (! empty($entry['drive'])) {
                        $result->put('drive_type', $entry['drive'], 'vds', 'medium');
                    }
                } elseif ($field === 'transmission') {
                    $kind = $entry['type'] ?? $this->matchHint(
                        $value.' '.($entry['note'] ?? ''),
                        self::TRANSMISSION_HINTS,
                    );
                    if ($kind === null) {
                        continue;   // «505 S» — это не коробка, а мусор из таблицы
                    }
                    $result->put('transmission', $value, 'vds', $confidence);
                    $result->put('transmission_type', $kind, 'vds', $confidence);
                    if (! empty($entry['drive'])) {
                        $result->put('drive_type', $entry['drive'], 'vds', 'medium');
                    }
                } elseif ($field === 'body') {
                    $result->put($field, $this->tables->bodiesRu()[$value] ?? $value, 'vds', $confidence);
                } elseif ($field === 'brand') {
                    // LVT в списке WMI записан за Chery, но под ним ездит и Exeed.
                    $result->put('brand', $value, 'vds_brand', $confidence);
                } else {
                    $result->put($field, $value, 'vds', $confidence);
                }
            }
        }

        $this->fillHints($result, implode(' ', $texts));
    }

    /**
     * Тип привода и КПП часто написан словами в самом описании кода:
     * «4DR Sedan 2WD», «5-spd. manual», «Coupe AWD». Вытаскиваем оттуда.
     */
    private function fillHints(VinResult $result, string $blob): void
    {
        if (trim($blob) === '') {
            return;
        }

        $drive = $this->matchHint($blob, self::DRIVE_HINTS);
        if ($drive !== null) {
            $result->put('drive_type', $drive, 'vds', 'medium');
        }
        $transmission = $this->matchHint($blob, self::TRANSMISSION_HINTS);
        if ($transmission !== null) {
            $result->put('transmission_type', $transmission, 'vds', 'medium');
        }
    }

    private function matchHint(string $text, array $hints): ?string
    {
        foreach ($hints as $pattern => $value) {
            if (preg_match($pattern, $text) === 1) {
                return $value;
            }
        }

        return null;
    }

    private function sameBrand(string $left, string $right): bool
    {
        $key = static fn (string $text): string => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($text)) ?? '';
        [$a, $b] = [$key($left), $key($right)];

        return $a !== '' && $b !== '' && (str_starts_with($a, $b) || str_starts_with($b, $a));
    }

    private function fillEngineSpecs(VinResult $result): void
    {
        $code = $result->get('engine');
        $specs = is_string($code) ? ($this->tables->engines()[$code] ?? null) : null;
        if ($specs === null) {
            return;
        }

        $result->put('engine', $specs['name'] ?? $code, 'engines', 'high');
        $result->put('displacement', $specs['displacement'] ?? null, 'engines', 'high');
        $result->put('power_hp', $specs['power_hp'] ?? null, 'engines', 'medium');
        $result->put('fuel_type', $specs['fuel_type'] ?? null, 'engines', 'high');
    }

    /**
     * @return array<string, array>
     */
    private function decodeTable(string $vin, string $name, VinResult $result): array
    {
        $table = $this->tables->vdsTable($name);
        if ($table === []) {
            return [];
        }

        $vds = substr($vin, 3, 6);
        $vis = substr($vin, 9);
        $year = $result->get('year');
        $year = is_int($year) ? $year : null;

        $out = [];
        if (! empty($table['model_code_only'])) {
            // Заводской индекс отдаём всегда, название — если таблица его знает.
            if (! ctype_digit($vds[0] ?? '')) {
                return [];
            }
            $out['model_code'] = ['value' => $vds, 'confidence' => 'high'];
        }

        $prefixes = $table['model_by_prefix'] ?? [];
        if ($prefixes !== []) {
            $lengths = array_unique(array_map('strlen', array_keys($prefixes)));
            rsort($lengths);
            $matched = false;
            foreach ($lengths as $length) {
                $hit = $prefixes[substr($vds, 0, $length)] ?? null;
                if ($hit !== null) {
                    $chosen = $this->choose($this->entries($hit), $year) ?? [];
                    $chosen['confidence'] = 'high';
                    $chosen['code'] = substr($vds, 0, $length);
                    $out['model'] = $chosen;
                    $matched = true;

                    break;
                }
            }
            if (! $matched && ctype_digit($vds[0] ?? '')) {
                // индекс модели читается, но названия для него нет — отдаём код
                $out['model_code'] = ['value' => $vds, 'confidence' => 'high'];
            }
        }

        // Часть страниц книги описывает только американский рынок. За его
        // пределами берём из такой таблицы одну модель: индекс поколения завод
        // присваивает глобально, а кузов и двигатель кодируются под рынок.
        $modelOnly = ! empty($table['_market']) && $result->get('region') !== 'Северная Америка';
        $filler = substr($vin, 3, 3) === 'ZZZ';

        foreach ($table['sections'] ?? [] as $key => $spec) {
            $field = $spec['field'] ?? $key;
            if ($modelOnly && $field !== 'model') {
                continue;
            }
            if ($field === 'serial') {
                continue;
            }
            [$section, $index] = $spec['at'];
            if ($field === 'model' && $section === 'vin' && $this->width($index) < 2) {
                // Одна буква не называет модель. У Toyota под кодом «3» лежат и
                // Camry, и Celica, и Lexus SC300 — это серия, а не машина.
                continue;
            }
            if ($section === 'vin' && $filler) {
                $start = (int) explode(':', (string) $index)[0];
                if ($start >= 4 && $start <= 6) {
                    continue;   // ZZZ — заглушка, а не код
                }
            }
            $code = $this->readAt($vin, $vds, $vis, $section, $index);
            if ($code === null || $code === '') {
                continue;
            }
            if (! array_key_exists('map', $spec)) {
                $out[$field] = ['value' => $code, 'confidence' => 'high'];

                continue;
            }
            $chosen = $this->choose($this->entries($spec['map'][$code] ?? []), $year);
            if ($chosen === null || ($chosen['value'] ?? null) === null) {
                continue;
            }
            $chosen['code'] = $code;
            $rank = ['high' => 2, 'medium' => 1, 'low' => 0];
            if (isset($out[$field])
                && ($rank[$chosen['confidence'] ?? ''] ?? 0) <= ($rank[$out[$field]['confidence'] ?? ''] ?? 0)) {
                continue;
            }
            $out[$field] = $chosen;
        }

        $byModel = $table['engine_by_model'] ?? null;
        if ($byModel !== null && ! isset($out['engine']) && isset($out['model'])) {
            [$section, $index] = $byModel['_at'];
            $code = $this->readAt($vin, $vds, $vis, $section, $index);
            $candidates = $byModel[$out['model']['code'] ?? ''] ?? [];
            if ($code !== null && isset($candidates[$code])) {
                $out['engine'] = ['value' => $candidates[$code], 'confidence' => 'high'];
            }
        }

        return $out;
    }

    private function readAt(string $vin, string $vds, string $vis, string $section, mixed $index): ?string
    {
        if ($section === 'vin') {
            [$start, $end] = array_pad(explode(':', (string) $index), 2, null);
            $from = (int) $start - 1;
            $length = $end === null ? 1 : (int) $end - (int) $start;

            return substr($vin, $from, $length) ?: null;
        }

        $text = $section === 'vds' ? $vds : $vis;
        if (is_string($index) && str_ends_with($index, ':')) {
            return substr($text, (int) rtrim($index, ':')) ?: null;
        }

        return $text[(int) $index] ?? null;
    }

    /**
     * «Logan / Sandero / Duster» — это не название, а список.
     *
     * В рукописных таблицах один код нередко покрывает несколько моделей,
     * записанных через косую черту. Разворачиваем в отдельные варианты: пусть
     * их рассудит год выпуска, а не рассудит — поле останется пустым.
     *
     * @return list<array>
     */
    private function split(mixed $value): array
    {
        if (is_string($value) && str_contains($value, ' / ')) {
            return array_values(array_filter(array_map('trim', explode(' / ', $value))));
        }

        return [$value];
    }

    /** @return list<array> */
    private function entries(mixed $raw): array
    {
        if (is_array($raw) && array_is_list($raw)) {
            $out = [];
            foreach ($raw as $entry) {
                if (is_array($entry)) {
                    $out[] = $entry;

                    continue;
                }
                foreach ($this->split($entry) as $value) {
                    $out[] = ['value' => $value];
                }
            }

            return $out;
        }
        if (is_array($raw)) {
            return [$raw + ['value' => $raw['value'] ?? $raw['name'] ?? null]];
        }
        if ($raw === null || $raw === []) {
            return [];
        }

        return array_map(static fn ($value) => ['value' => $value], $this->split($raw));
    }

    /** Сколько символов VIN читает секция. */
    private function width(mixed $index): int
    {
        [$start, $end] = array_pad(explode(':', (string) $index), 2, null);

        return $end === null ? 1 : (int) $end - (int) $start;
    }

    /**
     * Все остальные названия — это выбранное плюс приписка?
     *
     * Комплектация не делает машину другой моделью, а вот 924 и 928 — разные
     * машины, и «Q7» с «Q8» тоже. Отличаем по началу строки на границе слова.
     *
     * @param  list<string>  $others
     */
    private function sameModel(?string $chosen, array $others): bool
    {
        if ($chosen === null || $chosen === '') {
            return false;
        }
        foreach ($others as $other) {
            if (! str_starts_with((string) $other, $chosen.' ')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Выбирает вариант по году выпуска.
     *
     * Диапазоны годов в книге американские: европейская Audi 8V пошла с 2013-го,
     * а там написано '15-'20. Если год известен, годы у вариантов есть, но ни
     * один не подошёл — это расхождение рынков, а не незнание модели: код один,
     * берём первый вариант. А когда годов нет вовсе (Porsche 92 — это и 924, и
     * 928), развести варианты нечем, и это честная неоднозначность.
     */
    private function choose(array $entries, ?int $year): ?array
    {
        if ($entries === []) {
            return null;
        }

        $fits = static function (array $entry) use ($year): bool {
            $span = $entry['years'] ?? null;
            if (! is_array($span) || $year === null) {
                return true;
            }

            return $span[0] <= $year && ($span[1] === null || $year <= $span[1]);
        };

        $fitting = array_values(array_filter($entries, $fits));
        $dated = array_values(array_filter($fitting, static fn ($e) => ! empty($e['years'])));

        if ($fitting !== []) {
            $pool = ($year !== null && $dated !== []) ? $dated : $fitting;
            $honest = true;
        } else {
            $pool = $entries;   // рынки разошлись — доверяем самому коду
            $honest = false;
        }

        $best = $pool[0];
        $others = [];
        foreach (array_slice($pool, 1) as $entry) {
            if (($entry['value'] ?? null) !== ($best['value'] ?? null)) {
                $others[] = $entry['value'];
            }
        }
        if ($others !== [] && $this->sameModel($best['value'] ?? null, $others)) {
            // «Tiguan» и «Tiguan Limited» — одна модель с разной комплектацией.
            $others = [];
        }

        if ($others !== []) {
            $best['alternatives'] = array_slice(array_unique($others), 0, 6);
            $best['ambiguous'] = $honest;
            $best['confidence'] = 'medium';
        } elseif ($year !== null && ! empty($best['years']) && $fitting !== []) {
            $best['confidence'] = 'high';
        } else {
            $best['confidence'] = 'medium';
        }

        return $best;
    }
}
