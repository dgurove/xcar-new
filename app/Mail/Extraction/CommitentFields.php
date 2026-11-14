<?php

namespace App\Mail\Extraction;

use App\Billing\PartyKind;
use Illuminate\Support\Carbon;

/**
 * Комитент из договора комиссии Совкомбанка (14.11.2026, новый ДКП ПРАЙМ: «за счёт и по поручению собственника — ФИО
 * (Паспорт …), на основании Договора комиссии № …»). Текст — как его отдаёт `AttachmentText` (docx: абзацы строками,
 * таблица реквизитов — одной строкой «КОМИССИОНЕР … | КОМИТЕНТ … |»). Номер — «ДОГОВОР КОМИССИИ № 542819/2026»; имя — из
 * преамбулы «… с одной стороны и Избицкий Александр Николаевич, именуемый в дальнейшем КОМИТЕНТ»; остальное — из его
 * колонки реквизитов: у физлица дата рождения, паспорт, кем и когда выдан, код, адрес регистрации; у организации
 * (ООО «ИТЕКО Россия», сам Совкомбанк) — адрес, ИНН, КПП, ОГРН. Не договор комиссии или имени нет — null.
 */
final class CommitentFields
{
    /** @return array{number: ?string, kind: PartyKind, party: array<string, mixed>}|null */
    public static function extract(string $text): ?array
    {
        $text = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}", "\r"], ' ', $text);
        if (! preg_match('/ДОГОВОР\s+КОМИССИИ/iu', $text)) {
            return null;
        }
        $number = preg_match('/ДОГОВОР\s+КОМИССИИ\s*№\s*([0-9A-ZА-ЯЁ][0-9A-ZА-ЯЁ\/\-]*)/iu', $text, $m) ? $m[1] : null;
        $flat = preg_replace('/\s+/u', ' ', $text);
        if (! preg_match('/с\s+одной\s+стороны\s*,?\s+и\s+(.+?)\s*,\s+(?:в\s+лице|именуем)/u', $flat, $m)) {
            return null;
        }
        $name = trim($m[1], ' ,');
        // Колонка комитента в реквизитах: после «| КОМИТЕНТ» до его подписи или конца ячейки.
        $block = preg_match('/\|\s*КОМИТЕНТ\s*:?\s*(.+?)(?:\s+КОМИТЕНТ\s*_|\s*\|)/u', $flat, $b) ? $b[1] : '';
        $company = preg_match('/[«"]|\b(?:ООО|АО|ПАО|ЗАО|ОАО|ИП)\b/u', $name) || ! preg_match('/Паспорт/u', $block);

        if ($company) {
            $party = array_filter([
                'name' => $name, 'full_name' => $name,
                'legal_address' => preg_match('/Юридический\s+адрес\s*:?\s*(.+?)\s+Реквизиты/u', $block, $a) ? trim($a[1], ' ,') : null,
                'inn' => preg_match('/ИНН\s*:?\s*(\d{10,12})/u', $block, $a) ? $a[1] : null,
                'kpp' => preg_match('/КПП\s*:?\s*(\d{9})/u', $block, $a) ? $a[1] : null,
                'ogrn' => preg_match('/ОГРН\s*:?\s*(\d{13,15})/u', $block, $a) ? $a[1] : null,
            ]);

            return ['number' => $number, 'kind' => PartyKind::Company, 'party' => $party];
        }
        $party = array_filter([
            'name' => $name,
            'birth_at' => preg_match('/Дата\s+рождения\s*:?\s*(\d{2}\.\d{2}\.\d{4})/u', $block, $a) ? self::date($a[1]) : null,
            'passport' => preg_match('/Паспорт\s*:?\s*(?:серия\s*)?(\d{2})\s?(\d{2})\s*№?\s*(\d{6})/u', $block, $a) ? $a[1].' '.$a[2].' '.$a[3] : null,
            'passport_issued_at' => preg_match('/Выдан[а]?\s*:?\s*(\d{2}\.\d{2}\.\d{4})/u', $block, $a) ? self::date($a[1]) : null,
            'passport_issued' => preg_match('/Выдан[а]?\s*:?\s*(?:\d{2}\.\d{2}\.\d{4}\s*г?\.?\s*)?(.+?)\s+(?:Код\s+подразделения|Адрес\s+регистрации|Дата\s+выдачи)/u', $block, $a) ? trim($a[1], ' ,') : null,
            'passport_code' => preg_match('/Код\s+подразделения\s*:?\s*(\d{3}-?\d{3})/u', $block, $a) ? $a[1] : null,
            'reg_address' => preg_match('/Адрес\s+регистрации\s*:?\s*(.+)$/u', $block, $a) ? trim($a[1], ' ,') : null,
        ]);

        return ['number' => $number, 'kind' => PartyKind::Person, 'party' => $party];
    }

    private static function date(string $d): ?string
    {
        $date = Carbon::createFromFormat('d.m.Y', $d);

        return $date ? $date->toDateString() : null;
    }
}
