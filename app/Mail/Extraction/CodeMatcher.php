<?php

namespace App\Mail\Extraction;

/** Формы кодов убытка, какие приходят в письмах. Новая страховая — новая строка. */
final class CodeMatcher
{
    private const CHARS = 'A-Z0-9\\/\\-';

    private const PATTERNS = [
        '[A-Z0-9]{4}\/046\/\d{5}\/\d{2}',   // АльфаСтрахование: 8991/046/00754/25
        '\d{6}[\/-]\d{4}',                  // Совкомбанк: 638482/2026 и 660612-2026
        'AUT-\d{2}-\d{6}',                  // Т-Страхование: AUT-26-744362
        'Y-\d{3}-\d{6}\/\d{2}',             // Т-Страхование: У-000-020349/26 (кириллическая У)
        'AC\d{2}K\d{6}',                    // ИНСАЙТ: АС26К166877
    ];

    /** @return list<string> в порядке появления в тексте */
    public function findAll(?string $text): array
    {
        $normalized = Code::normalize($text);
        if ($normalized === null || $normalized === '') {
            return [];
        }
        $found = [];
        foreach (self::PATTERNS as $pattern) {
            preg_match_all('/(?<!['.self::CHARS.'])(?:'.$pattern.')(?!['.self::CHARS.'])/u', $normalized, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [$code, $offset]) {
                $found[$code] ??= $offset;
            }
        }
        asort($found);

        return array_map('strval', array_keys($found));
    }
}
