<?php

namespace App\Support;

/**
 * Вес файла коротко (13.11.2026, владелец: «мне похер, 383.93 KB или 384 KB»): «812 б», «384 Кб», «2,4 Мб» — без сотых,
 * с запятой и неразрывным пробелом. Пишется мелко в значке файла под расширением (`x-ui.file-icon`).
 */
final class FileSize
{
    public static function short(?int $bytes): string
    {
        if ($bytes === null || $bytes < 0) {
            return '';
        }

        return match (true) {
            $bytes >= 1048576 => str_replace('.', ',', (string) round($bytes / 1048576, 1))."\u{00A0}Мб",
            $bytes >= 1024 => round($bytes / 1024)."\u{00A0}Кб",
            default => $bytes."\u{00A0}б",
        };
    }
}
