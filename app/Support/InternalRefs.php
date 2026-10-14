<?php

namespace App\Support;

use App\Cars\Car;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\CodeMatcher;
use App\Offers\Offer;
use App\Park\Vehicle;

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
            // Только целым словом: номер «12345» внутри телефона «9161234567» — не он (раньше вырезался и портил телефон).
            $text = (string) preg_replace('/(?<![\p{L}\p{N}])(?:ДЛ\s*)?'.preg_quote($ref, '/').'(?![\p{L}\p{N}])/iu', '', $text);
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

    /** Номера машины файла: у предложения — убыток (он же ДЛ у лизинга), у ТС парковки — её убыток и убыток предложения. */
    public static function refsOf(?object $model): array
    {
        return match (true) {
            $model instanceof Car, $model instanceof Offer, $model instanceof Vehicle => [$model->claim_ref],
            default => [],
        };
    }
}
