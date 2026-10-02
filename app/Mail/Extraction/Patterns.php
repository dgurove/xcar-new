<?php

namespace App\Mail\Extraction;

use App\Support\Phone;

/**
 * Формы тождеств ТС и людей в тексте — одно место на разбор писем CRM и парковки, шаблоны вендоров, документы
 * (`DocumentFields`), привязку веток и поиск. VIN строже (OCR, контрольная цифра, известный завод) — `Cars\Vin\VinText`,
 * здесь — что считать VIN в обычном тексте письма. Номера убытка — `CodeMatcher`, марка и модель — `Cars\Names`.
 */
final class Patterns
{
    /** VIN: 17 знаков без I, O, Q. */
    public const VIN = '/\b[A-HJ-NPR-Z0-9]{17}\b/u';

    /** Госномер слитно: «Р621ВЕ126». */
    public const PLATE = '/\b[АВЕКМНОРСТУХ]\d{3}[АВЕКМНОРСТУХ]{2}\d{2,3}\b/u';

    /** Госномер, как его пишут в документах и OCR: «А 123 ВС 77». */
    public const PLATE_SPACED = '/\b[АВЕКМНОРСТУХ]\s?\d{3}\s?[АВЕКМНОРСТУХ]{2}\s?\d{2,3}\b/u';

    /** Прицеп: две буквы, четыре цифры, регион. */
    public const TRAILER = '/\b[АВЕКМНОРСТУХ]{2}\d{4}\d{2,3}\b/u';

    /** Год выпуска — кусок регулярки, без границ: 1980–2039. */
    public const YEAR = '19[89]\d|20[0-3]\d';

    /** Мобильный: «+7 (900) 123-45-67», «8-985-171-19-90», «89001234567» — кусок регулярки. */
    public const PHONE = '(?:\+?7|8)[\s(\-]*\d{3}[\s)\-]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}(?!\d)';

    /** Служебные приставки темы: «Re:», «Fwd:», «Отв:», «Пересылка:». */
    private const SUBJECT_PREFIX = '/^\s*(?:(?:r[eе](?:\[\d+\])?|fwd?|отв|ответ|пересылка|пересл\w*)\s*:\s*)+/iu';

    /** VIN в тексте письма, по порядку; заглушки вроде «11111111111111111» и «00000000000000001» — не VIN. @return list<string> */
    public static function vins(?string $text): array
    {
        if ($text === null || $text === '' || ! preg_match_all(self::VIN, mb_strtoupper($text), $m)) {
            return [];
        }

        return array_values(array_unique(array_filter($m[0], fn ($vin) => strlen(count_chars($vin, 3)) >= 4)));
    }

    public static function vin(?string $text): ?string
    {
        return self::vins($text)[0] ?? null;
    }

    /** Госномер слитно заглавными — так он лежит в базе и в ключах: «р 621 ве 126» → «Р621ВЕ126»; пусто — null. */
    public static function plateKey(?string $plate): ?string
    {
        $key = mb_strtoupper((string) preg_replace('/\s+/u', '', (string) $plate));

        return $key !== '' ? $key : null;
    }

    /** Телефоны текста в одном виде («+7 900 123-45-67»), без повторов. @return list<string> */
    public static function phones(?string $text): array
    {
        preg_match_all('/'.self::PHONE.'/u', (string) $text, $m);

        return array_values(array_unique(array_map(fn ($p) => Phone::format(Phone::normalize($p) ?? $p), $m[0])));
    }

    /** Тема без «Re:», «Fwd:» и прочих приставок пересылки и ответа. */
    public static function cleanSubject(?string $subject): string
    {
        return trim((string) preg_replace(self::SUBJECT_PREFIX, '', (string) $subject));
    }

    /**
     * Регулярка, которая находит номер убытка в исходном тексте: найден он нормализованным (`Code::normalize` —
     * латиница), а в теме бывает кириллицей («У-001-…», «АС26К…»). Нужна, чтобы вырезать номер из темы.
     */
    public static function codeRegex(string $code): string
    {
        return '/'.strtr(preg_quote($code, '/'), ['Y' => '[YУ]', 'A' => '[AА]', 'C' => '[CС]', 'K' => '[KК]', 'T' => '[TТ]', 'E' => '[EЕ]', 'H' => '[HН]', 'M' => '[MМ]', 'O' => '[OО]', 'P' => '[PР]', 'B' => '[BВ]', 'X' => '[XХ]']).'/iu';
    }
}
