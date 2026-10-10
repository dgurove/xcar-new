<?php

namespace App\Search;

use App\Cars\Vin\Vin;
use App\Mail\Extraction\Code;
use App\Support\Phone;

/**
 * Запрос поиска, разобранный один раз на всё, что из него можно вынуть (10.10.2026, владелец: «поиск должен быть прям
 * охуенным», «от кусков старого поиска везде избавиться»): номер убытка в любой записи и куском («647953»,
 * «647953-26»), VIN куском, госномер латиницей или кириллицей, телефон, номер предложения и счёта, слова. Одна дверь
 * для луп всех списков и для поиска в шапке трёх хостов: скоупы моделей (`Offer::search`, `Vehicle::search`,
 * `User::search`, `Invoice::search` …) принимают `string|Term`.
 */
final class Term
{
    public readonly string $raw;

    /** Номер убытка ключом (`Code::key`) — от четырёх цифр; сравнение подстрокой. */
    public readonly ?string $claim;

    /** Ключ номера без требования к цифрам — для сравнения `ref_key`/`claim_ref_key` куском; null у пустого. */
    public readonly ?string $key;

    /** Кусок VIN — латиница и цифры от пяти знаков (`Vin::normalize`). */
    public readonly ?string $vin;

    /** Кусок госномера без пробелов, латиницей. */
    public readonly ?string $plate;

    /** Телефон — цифры без кода страны (до десяти), от пяти. */
    public readonly ?string $phone;

    /** Только цифры (номер предложения, счёта, прежний номер). */
    public readonly ?string $digits;

    /** «счёт 12», «№ 12», голое число до шести цифр — номер счёта. */
    public readonly ?int $invoice;

    /** @var list<string> слова запроса, строчными; каждое должно найтись хоть в чём-то */
    public readonly array $words;

    public function __construct(string $raw)
    {
        $this->raw = $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        $compact = self::latin(mb_strtoupper((string) preg_replace('/\s+/u', '', $raw)));
        $onlyDigits = (string) preg_replace('/\D+/', '', $raw);

        $key = self::meaningful($raw) ? Code::key($raw) : null;
        $this->key = $key !== '' ? $key : null;
        $this->claim = $this->key && preg_match_all('/\d/', $this->key) >= 4 ? $this->key : null;
        $vin = Vin::normalize($raw);
        $this->vin = $vin && strlen($vin) >= 5 && $vin === preg_replace('/[^A-Z0-9]/', '', $compact) && preg_match('/\d/', $vin) ? $vin : null;
        $this->plate = mb_strlen($compact) >= 3 && preg_match('/\d/', $compact) && preg_match('/^[A-Z0-9]+$/', $compact) ? $compact : null;
        $this->phone = strlen($onlyDigits) >= 5 && preg_match('/^[\d\s()+\-]+$/', $raw)
            ? substr(Phone::normalize($onlyDigits) ?? $onlyDigits, -10) : null;
        $this->digits = $onlyDigits !== '' && preg_match('/^[\d\s№#]+$/u', $raw) ? $onlyDigits : null;
        $this->invoice = preg_match('/^(?:сч[её]т|№|#)\s*№?\s*(\d{1,6})$/ui', $raw, $m) ? (int) $m[1]
            : ($this->digits !== null && strlen($this->digits) <= 6 ? (int) $this->digits : null);
        $this->words = array_values(array_filter(preg_split('/[\s,]+/u', mb_strtolower($raw)) ?: [], fn ($w) => self::meaningful($w)));
    }

    public static function of(string|self $raw): self
    {
        return $raw instanceof self ? $raw : new self($raw);
    }

    /** Нечего искать: пусто или ни буквы, ни цифры («№», «-») — иначе `like '%%'` нашёл бы всё. */
    public function blank(): bool
    {
        return ! self::meaningful($this->raw);
    }

    /** Строка для `like` подстрокой: `%` и `_` из запроса — буквально. */
    public static function like(string $value, bool $prefix = false): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        return ($prefix ? '' : '%').$escaped.'%';
    }

    /** `like` по запросу целиком, строчными. */
    public function contains(): string
    {
        return self::like(mb_strtolower($this->raw));
    }

    /** Кириллица того же начертания — латиницей (`Code::HOMOGLYPHS`). */
    public static function latin(string $upper): string
    {
        return strtr($upper, Code::HOMOGLYPHS);
    }

    /** SQL: столбец, где кириллица того же начертания переведена в латиницу (госномер). */
    public static function latinSql(string $column): string
    {
        return "translate({$column}, '".implode('', array_keys(Code::HOMOGLYPHS))."', '".implode('', Code::HOMOGLYPHS)."')";
    }

    /** SQL: только цифры столбца (телефоны пишут как попало). */
    public static function digitsSql(string $column): string
    {
        return "regexp_replace(coalesce({$column}, ''), '\\D', '', 'g')";
    }

    private static function meaningful(string $value): bool
    {
        return (bool) preg_match('/[\p{L}\p{N}]/u', $value);
    }
}
