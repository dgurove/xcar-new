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

    /**
     * Тело пересылки: своих слов нет, сразу блок «От: / Тема:». Ответ с цитатой прежней переписки внизу
     * («From: Storage… Subject: Re: …» под своим текстом) пересылкой не считается — иначе отправителем
     * становился наш же ящик, а темой — тема из цитаты, и письмо о Джили ложилось под Фотон.
     */
    public static function forwardedBody(?string $body): ?string
    {
        $text = self::strip($body);
        if ($text === '' || ! preg_match('/^\s*(?:От|От кого|From)\s*:/umi', $text, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $own = substr($text, 0, (int) $m[0][1]); // смещение preg в байтах
        $own = (string) preg_replace('/^-+\s*(?:Пересылаемое сообщение|Forwarded message|Original Message|Исходное сообщение)\s*-+\s*$/imu', '', $own);
        $own = (string) preg_replace('/(?:добрый\s+(?:день|вечер)|здравствуйте|коллеги|партн[её]ры|отправлено из[^\n]*|см\.\s*ниже|во вложении|fyi|fwd?)[!,.:\s]*/iu', '', $own);
        $own = trim((string) preg_replace('/[\s\p{P}]+/u', ' ', $own));

        return mb_strlen($own) < 25 ? substr($text, (int) $m[0][1]) : null;
    }

    /** Свои слова письма: до цитаты прежней переписки («From: … Subject: …», «-----Original Message-----»). У пересылки — всё. */
    public static function ownText(?string $body): string
    {
        $text = self::strip($body);
        if (self::forwardedBody($body) !== null || ! preg_match('/^\s*(?:От|От кого|From|-+\s*(?:Original Message|Исходное сообщение)\s*-+)\s*:?/umi', $text, $m, PREG_OFFSET_CAPTURE)) {
            return $text;
        }

        return trim(substr($text, 0, (int) $m[0][1]));
    }

    public static function forwardedSender(?string $body): ?string
    {
        $text = self::forwardedBody($body);
        if ($text === null) {
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
        $text = self::forwardedBody($body);
        if ($text === null || ! preg_match('/^\s*(?:Тема|Subject)\s*:\s*(.+)$/umi', $text, $m)) {
            return null;
        }
        $subject = (string) preg_replace('/^\s*(?:(?:fwd|fw|re|пересылка|пересл)\s*:\s*)+/ui', '', trim($m[1]));

        return $subject !== '' ? $subject : null;
    }
}
