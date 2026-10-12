<?php

namespace App\Park;

use App\Cars\HasLabels;

/**
 * Документ отчёта о приёме (12.10.2026): что уходит вендору сканом вместе с фото — список у вендора
 * (`vendors.intake_report_docs`). word — как сотрудники называли файл в письме («Альфа акт 1 чанган алсвин 0367.PDF»),
 * по нему же узнаём уже лежащий у ТС документ.
 */
enum ReportDoc: string
{
    use HasLabels;

    case Act = 'act';
    case Request = 'request';
    case Sts = 'sts';
    case Pts = 'pts';
    case Proxy = 'proxy';

    public function label(): string
    {
        return match ($this) {
            self::Act => 'Акт приёма',
            self::Request => 'Заявка',
            self::Sts => 'СТС',
            self::Pts => 'ПТС / ЭПТС',
            self::Proxy => 'Доверенность',
        };
    }

    /** Слово в имени файла письма. */
    public function word(): string
    {
        return match ($this) {
            self::Act => 'акт',
            self::Request => 'заявка',
            self::Sts => 'стс',
            self::Pts => 'птс',
            self::Proxy => 'дов',
        };
    }

    /** Вид документа по имени файла — уже лежащий у ТС скан встаёт в свой слот. */
    public static function ofName(string $name): ?self
    {
        $n = mb_strtolower(str_replace('ё', 'е', $name));

        return match (true) {
            (bool) preg_match('/акт\s*(прием|п\/п|\d|_|-|\s|$)|приема-передачи/u', $n) && ! str_contains($n, 'осмотр') => self::Act,
            str_contains($n, 'заявк') => self::Request,
            str_contains($n, 'стс') || str_contains($n, 'свидетельств') => self::Sts,
            str_contains($n, 'птс') || str_contains($n, 'паспорт тс') => self::Pts,
            (bool) preg_match('/(^|\s)дов(ер|\s|\.|$)/u', $n) => self::Proxy,
            default => null,
        };
    }
}
