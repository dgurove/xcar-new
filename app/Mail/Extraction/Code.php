<?php

namespace App\Mail\Extraction;

/** Код убытка страховой в одной записи: верхний регистр, без пробелов у разделителей, кириллические омоглифы → латиница. */
final class Code
{
    private const HOMOGLYPHS = ['У' => 'Y', 'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X'];

    public static function normalize(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $code);
        $code = mb_strtoupper(trim($code));
        $code = (string) preg_replace('/\s*([\/\\\\-])\s*/u', '$1', $code);
        $code = (string) preg_replace('/\s+/u', ' ', $code);

        return strtr($code, self::HOMOGLYPHS);
    }
}
