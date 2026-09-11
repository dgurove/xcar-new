<?php

namespace App\Mail\Extraction;

/** Письма приходят пересылкой: настоящий отправитель и тема сидят в теле за «От:» и «Тема:». */
final class QuotationStripper
{
    public static function strip(?string $body): string
    {
        if ($body === null) {
            return '';
        }
        $lines = array_map(fn ($l) => (string) preg_replace('/^\s*(?:>\s?)+/u', '', $l), preg_split('/\r\n|\r|\n/u', $body) ?: []);

        return trim(implode("\n", $lines));
    }

    public static function forwardedSender(?string $body): ?string
    {
        $text = self::strip($body);
        if ($text === '') {
            return null;
        }
        $headers = 'От|От кого|From';
        if (preg_match('/^\s*(?:'.$headers.')\s*:.*?<([^<>@\s]+@[^<>@\s]+)>/umi', $text, $m)) {
            return mb_strtolower($m[1]);
        }
        if (preg_match('/^\s*(?:'.$headers.')\s*:\s*([^<>@\s]+@[^<>@\s]+)/umi', $text, $m)) {
            return mb_strtolower(rtrim($m[1], '.,;'));
        }

        return null;
    }

    public static function forwardedSubject(?string $body): ?string
    {
        $text = self::strip($body);
        if ($text === '' || ! preg_match('/^\s*(?:Тема|Subject)\s*:\s*(.+)$/umi', $text, $m)) {
            return null;
        }
        $subject = (string) preg_replace('/^\s*(?:(?:fwd|fw|re|пересылка|пересл)\s*:\s*)+/ui', '', trim($m[1]));

        return $subject !== '' ? $subject : null;
    }
}
