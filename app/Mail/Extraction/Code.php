<?php

namespace App\Mail\Extraction;

/** Код убытка страховой в одной записи: верхний регистр, без пробелов у разделителей, кириллические омоглифы → латиница. */
final class Code
{
    /** Кириллица того же начертания — латиницей: одна карта на номера убытка и поиск (`Search\Term`). */
    public const HOMOGLYPHS = ['У' => 'Y', 'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X'];

    /** Номера нет (17.10.2026, ТИТ): так пишут в поле, у вендора с `claim_ref_optional`. Ключа у него нет — машину не держит. */
    public const NONE = 'б/н';

    /** «б/н», «б\н», «бн», «б.н», «без номера» — номера нет. */
    public static function isNone(?string $code): bool
    {
        return (bool) preg_match('/^\s*(б\s*[\/\\\\.-]?\s*н\.?|без\s+номера)\s*$/iu', (string) $code);
    }

    /** Ключ для поиска: нормализованный код без разделителей в нижнем регистре — один и тот же у оффера, машины и письма. */
    public static function key(?string $code): ?string
    {
        if (self::isNone($code)) {
            return null;
        }
        $normalized = self::normalize($code);

        return $normalized ? mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $normalized)) : null;
    }

    public static function normalize(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $code);
        $code = mb_strtoupper(trim($code));
        $code = (string) preg_replace('/\s*([\/\\\\-])\s*/u', '$1', $code);
        $code = (string) preg_replace('/\s+/u', ' ', $code);
        // Совкомбанк пишет один номер тремя способами: 473697/2026, 473697-2026 и 473697-26 — запись одна (от 4 до 7 цифр:
        // 5025/2026, 49251-26, 1197110/2025).
        $code = (string) preg_replace(['/^(\d{4,7})[\/-](\d{2})$/', '/^(\d{4,7})-(\d{4})$/'], ['$1/20$2', '$1/$2'], $code);

        return strtr($code, self::HOMOGLYPHS);
    }
}
