<?php

namespace App\Offers;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Исправленный договор (19.11.2026, владелец: «либо отправить как есть, либо скачать docx, отредактировать и отправить менеджеру
 * его»). Сформированный договор не хранится — собирается из данных сделки при каждом открытии; исправленный — исключение по воле
 * сотрудника: файл, что он приложил (`dkp_edited` у сделки: Word или PDF), и его PDF (`dkp_edited_pdf`). Пока он есть, договор
 * сделки — он: в CRM, в шторке документов и у менеджера (`DealContractController::file`). Данные сделки поменялись после правки —
 * `stale()`: строка договора скажет красным, правку сделали по прежним данным. Убирает его «Вернуть сформированный»
 * (`Actions\EditContract::revert`) — пока договор не отправлен.
 */
final class ContractEdit
{
    /** Что принимаем исправленным: Word и его родня (LibreOffice делает из них PDF) и готовый PDF. */
    public const WORD = ['docx', 'doc', 'odt', 'rtf'];

    public const ACCEPT = '.docx,.doc,.odt,.rtf,.pdf';

    public static function has(Deal $deal): bool
    {
        return DealContract::for($deal)->edited_at !== null && self::original($deal) !== null;
    }

    /** Приложенный файл как есть. */
    public static function original(Deal $deal): ?Media
    {
        return $deal->getFirstMedia('dkp_edited');
    }

    /** Его PDF: у Word — собранный при загрузке, у PDF — он сам. */
    public static function pdf(Deal $deal): ?Media
    {
        $original = self::original($deal);

        return $original && self::isPdf($original) ? $original : $deal->getFirstMedia('dkp_edited_pdf');
    }

    public static function isPdf(Media $media): bool
    {
        return strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION)) === 'pdf';
    }

    /** Word исправленного есть — приложили Word; приложили PDF — скачать можно только PDF. */
    public static function hasWord(Deal $deal): bool
    {
        $original = self::original($deal);

        return $original === null || ! self::isPdf($original);
    }

    /** Отпечаток сформированного договора: с ним сверяемся, менялись ли данные после правки. */
    public static function fingerprint(Deal $deal): string
    {
        return sha1(DkpWord::html($deal));
    }

    /** Правили по прежним данным: сформированный договор сейчас другой, чем был в момент правки. */
    public static function stale(Deal $deal): bool
    {
        $base = DealContract::for($deal)->edited_base;

        return self::has($deal) && $base !== null && $base !== self::fingerprint($deal);
    }
}
