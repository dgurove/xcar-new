<?php

namespace App\Offers;

use App\Cars\Settlement;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\CodeMatcher;
use App\Mail\Extraction\Patterns;

/**
 * Оценочные стоимости из сообщения, которое модератору присылают текстом (владелец, 05.10.2026; образец — Альфа:
 * номер убытка, значок, дата, «635 237.00», VIN, город, марка — каждое своей строкой, между ними пустые). Блок машины —
 * от строки с номером убытка до следующей такой строки; абзацы роли не играют. Из блока берутся сумма (первая
 * денежная — с копейками, дата «22.09.26» ею не бывает), VIN и город (владелец 05.10.2026: на Мигторге VIN нет, а город
 * бывает устаревшим — здесь они от страховой): VIN дописывается в пустой, город главнее вписанного.
 */
final class ValuationText
{
    /** Сумма с копейками: «635 237.00», «1 579 000,00»; соседние цифры и точки — значит дата или часть другого числа. */
    private const MONEY = '/(?<![\d.,])(\d{1,3}(?:[ \x{A0}\x{202F}]\d{3})*)[.,](\d{2})(?![\d.,])/u';

    /** Без копеек — только с разрядами или от четырёх цифр подряд: «635 237», «635237». */
    private const WHOLE = '/(?<![\p{L}\d.,])(\d{1,3}(?:[ \x{A0}\x{202F}]\d{3})+|\d{4,9})(?![\p{L}\d.,])/u';

    /**
     * @return array{rows: list<array{ref: string, key: string, amount: ?int, vin: ?string, city: ?array{id: int, name: string, title: string}, lines: list<int>}>, lines: list<string>}
     *                                                                                                                               `lines` у строки — номера строк текста, где номер, сумма, VIN и город (для анимации «убираем лишнее»)
     */
    public static function parse(string $text): array
    {
        $lines = preg_split('/\R/u', str_replace("\u{2028}", "\n", $text)) ?: [];
        $matcher = new CodeMatcher;
        $rows = [];
        $current = null;
        foreach ($lines as $i => $line) {
            // Мессенджер мог склеить всё в одну строку: строка режется перед каждым номером убытка.
            foreach (self::segments($line, $matcher) as [$ref, $rest]) {
                if ($ref !== null) {
                    $current !== null && $rows[] = $current;
                    $current = ['ref' => $ref, 'key' => (string) Code::key($ref), 'amount' => null, 'vin' => null, 'city' => null, 'lines' => [$i]];
                }
                if ($current === null) {
                    continue;
                }
                if ($current['amount'] === null && ($amount = self::amount($rest)) !== null) {
                    $current['amount'] = $amount;
                    $current['lines'] = array_values(array_unique([...$current['lines'], $i]));
                }
                if ($current['vin'] === null && ($vin = Patterns::vins($rest)[0] ?? null)) {
                    $current['vin'] = $vin;
                    $current['lines'] = array_values(array_unique([...$current['lines'], $i]));
                }
                if ($current['city'] === null && $ref === null && ($city = self::city($rest))) {
                    $current['city'] = $city;
                    $current['lines'] = array_values(array_unique([...$current['lines'], $i]));
                }
            }
        }
        $current !== null && $rows[] = $current;

        return ['rows' => $rows, 'lines' => $lines];
    }

    /** @return list<array{0: ?string, 1: string}> куски строки: номер убытка (или null — продолжение блока) и текст после него */
    private static function segments(string $line, CodeMatcher $matcher): array
    {
        $upper = mb_strtoupper($line);
        $cuts = [];
        foreach ($matcher->findAll($line) as $ref) {
            $at = mb_strpos($upper, $ref);
            $at === false || $cuts[$at] = $ref;
        }
        if ($cuts === []) {
            return [[null, $line]];
        }
        ksort($cuts);
        $out = [];
        $first = array_key_first($cuts);
        $first > 0 && $out[] = [null, mb_substr($line, 0, $first)];
        $starts = array_keys($cuts);
        foreach ($starts as $n => $at) {
            $end = $starts[$n + 1] ?? mb_strlen($line);
            $out[] = [$cuts[$at], mb_substr($line, $at + mb_strlen($cuts[$at]), $end - $at - mb_strlen($cuts[$at]))];
        }

        return $out;
    }

    /**
     * Город — строка блока, которая целиком место справочника: «Москва», «г. Тверь», «Тверская обл., г. Ржев». В строке
     * города нет цифр (сумма, дата, VIN) — марка «Haval» или «Лада» городом справочника не бывает (`Settlement::named`
     * без деревень).
     *
     * @return array{id: int, name: string, title: string}|null
     */
    public static function city(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || mb_strlen($line) > 80 || preg_match('/\d/u', $line)) {
            return null;
        }

        return Settlement::named($line) ?? Settlement::inAddress($line);
    }

    /** Рубли без копеек; не сумма — null. */
    public static function amount(string $line): ?int
    {
        if (preg_match(self::MONEY, $line, $m) || preg_match(self::WHOLE, $line, $m)) {
            $n = (int) preg_replace('/\D/u', '', $m[1]);

            return $n > 0 ? $n : null;
        }

        return null;
    }
}
