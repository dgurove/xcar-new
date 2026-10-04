<?php

namespace App\Mail\Extraction;

/** Письма приходят пересылкой: настоящий отправитель и тема сидят в теле за «От:» и «Тема:». */
final class QuotationStripper
{
    private const FORWARD_MARK = '-+\s*(?:Пересылаемое сообщение|Forwarded message|Begin forwarded message)\s*-+';

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
        // Явная пересылка («-------- Пересылаемое сообщение --------» прямо над «От:») — пересылка, сколько бы сотрудник
        // ни написал над ней: его примечание — `forwardNote`, а не повод считать письмо ответом.
        if (preg_match('/(?:^|\n)\s*'.self::FORWARD_MARK.'\s*$/iu', $own)) {
            return substr($text, (int) $m[0][1]);
        }
        $own = (string) preg_replace('/^-+\s*(?:Пересылаемое сообщение|Forwarded message|Original Message|Исходное сообщение)\s*-+\s*$/imu', '', $own);
        $own = (string) preg_replace('/(?:добрый\s+(?:день|вечер)|здравствуйте|коллеги|партн[её]ры|отправлено из[^\n]*|см\.\s*ниже|во вложении|fyi|fwd?)[!,.:\s]*/iu', '', $own);
        $own = trim((string) preg_replace('/[\s\p{P}]+/u', ' ', $own));

        return mb_strlen($own) < 25 ? substr($text, (int) $m[0][1]) : null;
    }

    /**
     * Примечание того, кто переслал (Андрей пишет над пересылкой «Ростовские, 1190 отдали»): его слова до блока «От:»
     * без строки «Пересылаемое сообщение» и подписи телефона «Отправлено из…». Не пересылка или слов нет — null.
     */
    public static function forwardNote(?string $body): ?string
    {
        $forwarded = self::forwardedBody($body);
        if ($forwarded === null) {
            return null;
        }
        $text = self::strip($body);
        $note = substr($text, 0, strlen($text) - strlen($forwarded));
        $note = (string) preg_replace(['/^\s*'.self::FORWARD_MARK.'\s*$/imu', '/^\s*(?:Отправлено из|Sent from)[^\n]*$/imu'], '', $note);
        $note = trim((string) preg_replace("/\n{3,}/u", "\n\n", $note));

        return $note !== '' ? mb_substr($note, 0, 1000) : null;
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
        $subject = Patterns::cleanSubject($m[1]);

        return $subject !== '' ? $subject : null;
    }
}
