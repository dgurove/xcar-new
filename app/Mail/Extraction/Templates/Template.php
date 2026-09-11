<?php

namespace App\Mail\Extraction\Templates;

use App\Mail\Extraction\CodeMatcher;
use App\Mail\Extraction\QuotationStripper;

/**
 * Шаблон страховой: как из темы и тела достать машину. Поле — значение с
 * источником (subject/body), чтобы модератор видел, откуда что взялось.
 */
abstract class Template
{
    protected const VIN = '/\b[A-HJ-NPR-Z0-9]{17}\b/u';

    protected const PLATE = '/\b[АВЕКМНОРСТУХ]\d{3}[АВЕКМНОРСТУХ]{2}\d{2,3}\b/u';

    protected const YEAR = '/\b(?:19[89]\d|20[0-4]\d)\b/u';

    protected const STOP_WORDS = ['ТС', 'авто', 'автомашина', 'транспортное средство'];

    public function __construct(protected CodeMatcher $matcher = new CodeMatcher) {}

    abstract public function extract(string $subject, string $body): array;

    protected function subjectOf(?string $subject, ?string $body): string
    {
        $subject = QuotationStripper::forwardedSubject($body) ?? trim((string) $subject);

        return trim((string) preg_replace('/^\s*(?:(?:fwd|fw|re|пересылка|пересл)\s*:\s*)+/ui', '', $subject));
    }

    protected function firstCode(string $text): ?string
    {
        return $this->matcher->findAll($text)[0] ?? null;
    }

    protected function match(string $pattern, string $text): ?string
    {
        return $text !== '' && preg_match($pattern, mb_strtoupper($text), $m) ? $m[0] : null;
    }

    protected function put(array &$fields, string $field, mixed $value, string $source): void
    {
        if ($value !== null && $value !== '' && ! isset($fields[$field])) {
            $fields[$field] = ['value' => $value, 'source' => $source];
        }
    }

    protected function price(string $text): ?int
    {
        if ($text === '') {
            return null;
        }
        $n = '[\d\x{00A0}\x{2007}\x{202F}\s]';
        foreach ([
            '/Цена\s+за\s+(?:Оффер|Лот)\D{0,10}('.$n.'+)/ui',
            '/оценены\D{0,20}?('.$n.'{4,})\D{0,6}(?:руб|р\.)/ui',
            '/Максимальное\s+предложение\D{0,20}?('.$n.'{4,})\D{0,6}(?:руб|р\.)/ui',
        ] as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $digits = preg_replace('/\D/u', '', $m[1]) ?? '';
                if ($digits !== '' && (int) $digits > 0) {
                    return (int) $digits;
                }
            }
        }

        return null;
    }

    protected function location(string $text): ?string
    {
        if (! preg_match('/Местонахождение\s*:?\s*(.+)/ui', $text, $m)) {
            return null;
        }
        $value = rtrim(trim(trim((string) preg_replace('/\s+/u', ' ', $m[1])), "*: \t\n\r\0\x0B"), ' .;,');

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    protected function brandAndModel(string $text): array
    {
        if ($text === '') {
            return [null, null];
        }
        $head = (string) preg_replace('/\(.*$/us', '', $text);
        if (preg_match(self::YEAR, $head, $year, PREG_OFFSET_CAPTURE)) {
            $head = mb_substr($head, mb_strlen(mb_strcut($head, 0, $year[0][1] + strlen($year[0][0]))));
        } else {
            foreach ($this->matcher->findAll($head) as $code) {
                $position = mb_stripos($head, $code);
                if ($position !== false) {
                    $head = mb_substr($head, $position + mb_strlen($code));
                }
            }
        }
        $head = trim((string) preg_replace('/\s+/u', ' ', $head), " \t-–—,:;");
        do {
            $changed = false;
            foreach (self::STOP_WORDS as $word) {
                $pattern = '/^'.preg_quote($word, '/').'\s+/ui';
                if (preg_match($pattern, $head)) {
                    $head = trim((string) preg_replace($pattern, '', $head, 1));
                    $changed = true;
                }
            }
        } while ($changed);
        $head = trim((string) preg_replace('/\b(?:ч\.?\s*\d+|часть\s*\d+)\b/ui', ' ', $head));

        return $this->splitBrandModel($head);
    }

    protected function stripMarkdown(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[*_]+/u', '', $value)));
    }

    protected function splitBrandModel(string $text): array
    {
        $head = trim($this->stripMarkdown($text), " \t*_—–-:;,.");
        if ($head === '') {
            return [null, null];
        }
        $words = explode(' ', $head);
        $brand = array_shift($words);
        $model = implode(' ', $words);

        return [$brand, $model !== '' ? $model : null];
    }
}
