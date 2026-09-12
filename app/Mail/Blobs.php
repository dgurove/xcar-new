<?php

namespace App\Mail;

use Illuminate\Support\Facades\Storage;

/**
 * Закреплённые файлы писем: один файл на содержимое, имя — sha256. Одна и та же
 * фотография из пяти писем ветки и пересылок лежит на диске один раз; файл
 * уходит, когда на него не ссылается ни одно вложение.
 */
final class Blobs
{
    public const DISK = 'private';

    public static function put(string $contents): string
    {
        $sha = hash('sha256', $contents);
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists(self::path($sha))) {
            $disk->put(self::path($sha), $contents);
        }

        return $sha;
    }

    public static function path(string $sha): string
    {
        return 'blobs/'.substr($sha, 0, 2).'/'.$sha;
    }

    public static function absolute(string $sha): ?string
    {
        $disk = Storage::disk(self::DISK);

        return $disk->exists(self::path($sha)) ? $disk->path(self::path($sha)) : null;
    }

    /** Файл больше никому не нужен — стереть. */
    public static function release(string $sha): void
    {
        if (Attachment::where('blob_sha', $sha)->doesntExist()) {
            Storage::disk(self::DISK)->delete(self::path($sha));
        }
    }
}
