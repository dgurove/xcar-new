<?php

namespace App\Support;

use App\Mail\Extraction\Code;
use App\Mail\Extraction\CodeMatcher;

/**
 * Номер убытка и ДЛ — внутренние: на xcar их нет нигде (владелец 10.10.2026), а в чужом тексте (ответ страховой, имя
 * файла) они встречаются сами. Вырезаем номера по формам `CodeMatcher` и известные номера машины дословно.
 */
final class InternalRefs
{
    /** @param  list<?string>  $refs  номера этой машины: убыток, ДЛ — в любой форме, какой их вписали */
    public static function hide(string $text, array $refs = []): string
    {
        $refs = array_values(array_unique(array_filter(array_map(fn ($r) => trim((string) $r), $refs), fn ($r) => mb_strlen($r) >= 4)));
        foreach ($refs as $ref) {
            $text = (string) preg_replace('/(?:\bДЛ\s*)?'.preg_quote($ref, '/').'/iu', '', $text);
        }
        // Слово с разделителями «/», «-», «№» или длинное число — номер, если подходит под форму убытка целиком или это
        // номер машины в другой записи («8991-046-00754-25» в имени файла при «8991/046/00754/25» в карточке).
        $keys = array_filter(array_map(fn ($r) => Code::key($r), $refs));
        $text = (string) preg_replace_callback('/(?<![\p{L}\p{N}])(?:[\p{L}\p{N}]+(?:[\/\-№][\p{L}\p{N}]+)+|\p{N}{8,})(?![\p{L}\p{N}])/u',
            fn ($m) => CodeMatcher::patternOf((string) Code::normalize($m[0])) || in_array(Code::key($m[0]), $keys, true) ? '' : $m[0], $text);

        // Хвосты от вырезанного: «№» без номера, двойные пробелы, пробел перед знаком препинания, пустые скобки.
        return trim((string) preg_replace(['/№[ \t]*(?=[\s,.;:)_]|$)/mu', '/\([ \t]*\)/u', '/[ \t]{2,}/u', '/[ \t]+([,.;:)])/u'], ['', '', ' ', '$1'], $text));
    }

    /** Имя файла: «473697-26.pdf» — не «.pdf», а «Документ.pdf». */
    public static function fileName(string $name, array $refs = []): string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = self::hide($ext !== '' ? mb_substr($name, 0, -mb_strlen($ext) - 1) : $name, $refs);
        $base = trim($base, " \t_-.,");

        return ($base !== '' ? $base : 'Документ').($ext !== '' ? '.'.$ext : '');
    }

    /** На xcar — без номеров, в CRM и на парковке — как есть. */
    public static function forSite(string $text, array $refs = []): string
    {
        return Surface::current() === Surface::Site ? self::hide($text, $refs) : $text;
    }
}
