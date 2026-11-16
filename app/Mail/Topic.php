<?php

namespace App\Mail;

use App\Mail\Extraction\Patterns;
use App\Mail\Extraction\QuotationStripper;

/**
 * Общая тема переписки (17.11.2026, владелец: «не „Переписка по машине“, а „Тема писем: …“», «в окошке вместо „Письма“ —
 * тему писем»): темы писем дела чуть разные — «RE:», «Fwd:», «ч.2», регистр, — но у большинства одна. Тема письма — без
 * приставок и хвоста «ч.N», у пересылки сотрудника — из цитаты; сравниваются строчными без знаков. Побеждает та, что у
 * большинства писем, при равенстве — у самого раннего; показывается её первая запись как есть.
 */
final class Topic
{
    /** @param iterable<Message> $messages */
    public static function of(iterable $messages): ?string
    {
        $seen = [];
        $sorted = collect($messages)->sortBy(fn (Message $m) => $m->date_at?->getTimestamp() ?? 0)->values();
        foreach ($sorted as $i => $m) {
            $topic = self::clean(($m->isForwardedByStaff() ? QuotationStripper::forwardedSubject($m->text_body) : null) ?? $m->subject);
            $key = self::key($topic);
            if ($key === '') {
                continue;
            }
            $seen[$key] ??= ['topic' => $topic, 'n' => 0, 'first' => $i];
            $seen[$key]['n']++;
        }
        if (! $seen) {
            return null;
        }
        uasort($seen, fn ($a, $b) => [$b['n'], $a['first']] <=> [$a['n'], $b['first']]);

        return reset($seen)['topic'];
    }

    /** Тема письма для показа: без «RE: / Fwd:», без «ч.2» и пустой закодированной «=?UTF-8?B??=». */
    public static function clean(?string $subject): string
    {
        $s = (string) preg_replace('/=\?[^?]+\?[bq]\?\?=/i', '', (string) $subject);
        $s = Patterns::cleanSubject($s);
        $s = (string) preg_replace('/[\s(]*ч\.?\s*\d+\s*\)?\s*$/iu', '', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** Та же тема, что общая, — у письма её не повторять. */
    public static function same(?string $subject, ?string $topic): bool
    {
        return $topic !== null && self::key(self::clean($subject)) === self::key($topic);
    }

    private static function key(string $topic): string
    {
        return (string) preg_replace('/[^\pL\pN]+/u', '', mb_strtolower(str_replace('ё', 'е', $topic)));
    }
}
