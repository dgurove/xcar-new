<?php

namespace App\Offers;

use App\Mail\Message;
use App\Support\InternalRefs;
use App\Support\VendorNames;

/**
 * Ответ страховой — то, что из него можно показать на сайте (15.10.2026, владелец: менеджеру показали письмо Совкомбанка
 * целиком — «Новая стоимость – 433 200 руб.», ФИО, должность, телефон и почта сотрудника страховой; «никаких названий
 * вендора, подписи сотрудника, письма урезанные»). Подпись — от первой её строки до конца; строка с суммой, вендором,
 * почтой, ссылкой, 8-800 или фамилией отправителя уходит целиком. Ничего не осталось — null: письма на сайте нет.
 */
final class ReplyText
{
    /** Строка, с которой начинается подпись. */
    private const SIGNATURE = '/^\s*(?:--|[—–_-]{3,}|с\s+уважением|best\s+regards|kind\s+regards|regards\b)|руководител|начальник|специалист|эксперт|менеджер'
        .'|управлени|отдел\b|отдела|департамент|направлени|урегулировани|филиал|e-?mail|@|https?:|www\.|\[cid:'
        .'|\((?:ао|пао|ооо)\)|(?<![\p{L}])(?:пао|ооо|зао|ао)\s*[«"]/iu';

    /** Строка, которой на сайте нет, где бы она ни стояла. */
    private const DROP = '/\d[\d\s.,]*\s*(?:руб|р\.|₽|рубл|тыс|т\.р)|@|https?:|www\.|cid:|\b8[\s(-]*800\b/iu';

    public static function for(Message $message, Offer $offer): ?string
    {
        $text = InternalRefs::hide($message->replyText(), [$offer->claim_ref]);
        $lines = preg_split('/\R/u', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            if (preg_match(self::SIGNATURE, $line)) {
                // ФИО строкой прямо над подписью — тоже подпись.
                while ($out && preg_match('/^\s*(?:\p{Lu}[\p{Ll}-]+\s*){2,3}$/u', end($out))) {
                    array_pop($out);
                }
                break;
            }
            $out[] = $line;
        }
        $surnames = self::senderWords($message);
        $out = array_filter($out, fn ($line) => ! preg_match(self::DROP, $line) && ! VendorNames::matches($line)
            && ! ($surnames && preg_match('/(?<![\p{L}])(?:'.$surnames.')(?![\p{L}])/iu', $line)));
        $text = trim((string) preg_replace("/\n{3,}/u", "\n\n", implode("\n", $out)));

        return preg_match('/[\p{L}\p{N}]/u', $text) ? $text : null;
    }

    /** Имя и фамилия отправителя письма — сотрудник страховой не называется. */
    private static function senderWords(Message $message): string
    {
        $words = array_filter(preg_split('/[^\p{L}-]+/u', (string) $message->from_name) ?: [], fn ($w) => mb_strlen($w) > 2);

        return implode('|', array_map(fn ($w) => preg_quote($w, '/'), $words));
    }
}
