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
        'Y-\d{3}-\d{6}\/\d{2}(?:\/\d)?',     // Т-Страхование, Абсолют: У-000-020349/26, У-001-418053/24/3 (кириллическая У)
        'AC\d{2}K\d{6}',                    // ИНСАЙТ: АС26К166877
        'SGZA ?\d{10}\/?[A-Z]{0,2}№\d{7}(?:Д\d?)?',                         // СОГАЗ: SGZA0000760489D№0000002, SGZA 0001685999/RD№0000001Д1
        '\d{2,4}(?:-\d{2})? MT \d{4,5}[A-Z]{0,5}(?:\/[A-Z]{3,5})?(?:\/\d{2}D)?№\d{7}(?:Д\d?)?', // СОГАЗ: 24-82 MT 2467VTBD/AOND№0000001, 1824-41 MT 1001/AONAB/04D№0000056, 1023 MT 0787KL/AOND№0000001, 1821-82 MT 6429MTAD№0000002
        'MCQ-\d{10}D№\d{7}',                // СОГАЗ: MCQ-0001979781D№0000082
        '00\d{8}',                          // Росгосстрах: 0020281358
        'C\d{7}',                           // Интери: С2500291 (кириллическая С)
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
