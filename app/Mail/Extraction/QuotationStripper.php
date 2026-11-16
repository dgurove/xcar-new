<?php

namespace App\Mail\Extraction;

/** Письма приходят пересылкой: настоящий отправитель и тема сидят в теле за «От:» и «Тема:». */
final class QuotationStripper
{
    public const FORWARD_MARK = '-+\s*(?:Пересылаемое сообщение|Forwarded message|Begin forwarded message)\s*-+';

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

    /** Имя настоящего отправителя пересылки («От: Марина Кардышева <…>») — подпись письма узнаётся по нему. */
    public static function forwardedName(?string $body): ?string
    {
        $text = self::forwardedBody($body);
        if ($text === null || ! preg_match('/^\s*(?:От|От кого|From)\s*:\s*"?([^"<>\n@]+?)"?\s*</umi', $text, $m)) {
            return null;
        }

        return trim($m[1]) ?: null;
    }

    /**
     * Кому и когда писал сам поставщик — из шапки пересылки (17.11.2026, владелец: «не „переслал“ и не наш ящик, а
     * оригинальное кому и во сколько»): «Кому» и «Копия» — имена (без имени — адрес), «Дата» — «пятница, 30 октября 2026 г.
     * в 17:18 +03:00», «Пятница, 6 ноября 2026, 13:26 +03:00», «Fri, Oct 30, 2026 at 5:18 PM». Не прочлось — null.
     *
     * @return array{to: list<string>, date: ?\Illuminate\Support\Carbon}|null
     */
    public static function forwardedHead(?string $body): ?array
    {
        $text = self::forwardedBody($body);
        if ($text === null) {
            return null;
        }
        $to = [];
        $date = null;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (! preg_match(self::HEADER_LINE, $line)) {
                if (trim($line) === '' && ($to || $date)) {
                    break;
                }
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            $key = mb_strtolower($key);
            if (in_array($key, ['кому', 'копия', 'to', 'cc'], true)) {
                preg_match_all('/\s*("?)([^",<]*?)\1\s*(?:<([^>]+)>)?\s*(?:,|$)/u', $value, $all, PREG_SET_ORDER);
                foreach ($all as $a) {
                    $name = trim($a[2]);
                    $mail = trim($a[3] ?? '');
                    $who = $name !== '' && mb_strtolower($name) !== mb_strtolower($mail) ? $name : ($mail ?: $name);
                    if ($who !== '' && ! in_array($who, $to, true)) {
                        $to[] = $who;
                    }
                }
            } elseif (in_array($key, ['дата', 'date', 'отправлено', 'sent'], true)) {
                $date = self::headDate($value);
            }
        }

        return $to || $date ? ['to' => $to, 'date' => $date] : null;
    }

    private static function headDate(string $value): ?\Illuminate\Support\Carbon
    {
        $months = ['янв' => 1, 'фев' => 2, 'мар' => 3, 'апр' => 4, 'мая' => 5, 'май' => 5, 'июн' => 6, 'июл' => 7, 'авг' => 8, 'сен' => 9, 'окт' => 10, 'ноя' => 11, 'дек' => 12,
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        $v = mb_strtolower($value);
        $tz = preg_match('/([+-]\d{2}):?(\d{2})\s*$/', $v, $o) ? $o[1].':'.$o[2] : 'Europe/Moscow';
        try {
            if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})\D+(\d{1,2}):(\d{2})/u', $v, $m)) {
                return \Illuminate\Support\Carbon::create((int) $m[3], (int) $m[2], (int) $m[1], (int) $m[4], (int) $m[5], 0, $tz);
            }
            if (preg_match('/(\d{1,2})\s+(\pL{3})\pL*\.?,?\s+(\d{4})\D+?(\d{1,2}):(\d{2})\s*(am|pm)?/u', $v, $m) && isset($months[$m[2]])) {
                $h = (int) $m[4] % 12 + (($m[6] ?? '') === 'pm' ? 12 : 0);
                return \Illuminate\Support\Carbon::create((int) $m[3], $months[$m[2]], (int) $m[1], ($m[6] ?? '') ? $h : (int) $m[4], (int) $m[5], 0, $tz);
            }
            if (preg_match('/(\pL{3})\pL*\.?\s+(\d{1,2}),?\s+(\d{4})\D+?(\d{1,2}):(\d{2})\s*(am|pm)?/u', $v, $m) && isset($months[$m[1]])) {
                $h = (int) $m[4] % 12 + (($m[6] ?? '') === 'pm' ? 12 : 0);
                return \Illuminate\Support\Carbon::create((int) $m[3], $months[$m[1]], (int) $m[2], ($m[6] ?? '') ? $h : (int) $m[4], (int) $m[5], 0, $tz);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** Письмо пересылки без шапки «От / Кому / Копия / Дата / Тема» — для показа. */
    public static function forwardedLetter(?string $body): ?string
    {
        $text = self::forwardedBody($body);
        if ($text === null) {
            return null;
        }
        $lines = preg_split('/\R/u', $text) ?: [];
        while ($lines && (preg_match(self::HEADER_LINE, $lines[0]) || trim($lines[0]) === '')) {
            array_shift($lines);
        }

        return trim(implode("\n", $lines));
    }

    /** Строка шапки пересылки или цитаты. */
    public const HEADER_LINE = '/^\s*(?:От|От кого|Кому|Копия|Дата|Тема|Отправлено|From|To|Cc|Date|Subject|Sent)\s*:/iu';

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
