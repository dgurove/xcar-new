<?php

namespace App\Offers;

use App\Mail\Message;

/**
 * Письмо поставщика «договор готов» (11.11.2026, Альфа по BMW X6 № 2610041185: «ДКП подготовлен. Для подписания ДКП приглашаем
 * Вас в офис по адресу: … С пн-пт 9:00-18:00 СТРОГО ПО ПРЕДВАРИТЕЛЬНОЙ ЗАПИСИ! (контакты для связи Ткаченко Евгений вн тел.
 * 600428 / …) ВАЖНО: ТС передается покупателю при предъявлении паспорта, либо доверенному лицу … нотариально заверенной
 * доверенности»). Разбор по своему тексту письма (`Message::replyText`): адрес и часы филиала — в сделку и вендору
 * (`Signing::office`), пункты «Показать менеджеру» — вместо телефонов страхователя и стоянки (`Disclosures::proposals`).
 */
final class SigningLetter
{
    public static function ready(Message $message): bool
    {
        return preg_match('/д[кk]п\s+подготовлен|договор\w*\s+(?:купли[\s-]+продажи\s+)?(?:подготовлен|готов\b)/u', mb_strtolower($message->replyText())) === 1;
    }

    /** «Москва, Золоторожский Вал, д.11, стр. 38» — после «по адресу:» до конца строки или до часов работы. */
    public static function address(string $text): ?string
    {
        if (! preg_match('/по\s+адресу\s*:?\s*([^\n]+)/iu', $text, $m)) {
            return null;
        }
        $address = preg_split('/\s+(?:с\s+)?(?:пн|понедельник)\b|\s+строго\b|\s*\(|\s+тел/iu', $m[1])[0];
        $address = trim($address, " \t.,;:!");

        return mb_strlen($address) >= 8 ? $address : null;
    }

    /** @return array{days: list<int>, from: string, to: string}|null «пн-пт 9:00-18:00» */
    public static function hours(string $text): ?array
    {
        $days = ['пн' => 1, 'вт' => 2, 'ср' => 3, 'чт' => 4, 'пт' => 5, 'сб' => 6, 'вс' => 7];
        if (! preg_match('/\b(пн|вт|ср|чт|пт|сб)\w*\s*[-–—]\s*(пн|вт|ср|чт|пт|сб|вс)\w*[\s,:с]*(\d{1,2})[:.](\d{2})\s*[-–—]\s*(\d{1,2})[:.](\d{2})/iu', $text, $m)) {
            return null;
        }

        return [
            'days' => range($days[mb_strtolower($m[1])], $days[mb_strtolower($m[2])]),
            'from' => sprintf('%02d:%s', $m[3], $m[4]),
            'to' => sprintf('%02d:%s', $m[5], $m[6]),
        ];
    }

    public static function byAppointment(string $text): bool
    {
        return preg_match('/по\s+(?:предварительной\s+)?записи/iu', self::officePart($text)) === 1;
    }

    /** @return list<array{name: string, phone: ?string, ext: ?string}> «Ткаченко Евгений вн тел. 600428» */
    public static function contacts(string $text): array
    {
        preg_match_all('/([А-ЯЁ][а-яё]+\s+[А-ЯЁ][а-яё]+)\s+вн\.?\s*тел\.?\s*(\d{3,7})/u', $text, $m, PREG_SET_ORDER);

        return array_map(fn ($r) => ['name' => $r[1], 'phone' => null, 'ext' => $r[2]], $m);
    }

    /** Покупателю — паспорт, доверенному — нотариальная доверенность. */
    public static function documents(string $text): ?string
    {
        return preg_match('/паспорт[^\n]{0,120}нотариальн/iu', $text) === 1
            ? 'Покупателю взять паспорт, доверенному лицу — нотариальную доверенность' : null;
    }

    /** Филиал письмом: что из него можно записать вендору (`vendors.signing_office`). */
    public static function office(Message $message): ?array
    {
        $text = $message->replyText();
        $address = self::address($text);
        if (! $address) {
            return null;
        }

        return ['address' => $address, ...(self::hours($text) ?? []), 'by_appointment' => self::byAppointment($text), 'contacts' => self::contacts($text)];
    }

    /** Пункт «Показать менеджеру»: «Подписание по записи: адрес, пн–пт 9:00–18:00». */
    public static function line(Message $message): ?string
    {
        $text = $message->replyText();
        if (! ($address = self::address($text))) {
            return null;
        }
        $hours = self::hours($text);
        $names = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];
        $hm = fn (string $t) => preg_replace('/^0(\d)/', '$1', $t);
        $when = $hours ? ', '.$names[$hours['days'][0]].'–'.$names[end($hours['days'])].' '.$hm($hours['from']).'–'.$hm($hours['to']) : '';

        return 'Подписание '.(self::byAppointment($text) ? 'только по записи' : 'у поставщика').': '.$address.$when;
    }

    /** Текст до сведений о стоянке: «обязательна предварительная запись» стоянки — не про офис. */
    private static function officePart(string $text): string
    {
        return preg_split('/телефон\s+стоянки|адрес\s+стоянки/iu', $text)[0];
    }
}
