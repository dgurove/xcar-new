<?php

namespace App\Media;

use Illuminate\Support\Facades\Storage;
use Spatie\Image\Image;
use Throwable;

/**
 * webp наружу — JPEG (15.11.2026, владелец: «всех бесит этот webp»): храним кадры webp (в 10 раз легче), а всё, что человек
 * открывает и скачивает, — документ (`Site\FileController`), файл чата (`Site\ChatController`), кадр из просмотра
 * (`JpegController`, кнопка «Скачать»), — отдаётся JPEG тем же видом, с именем `.jpg`. Пересчитывается раз, лежит в `cache`
 * (чистит `storage:gc`); не вышло — отдаём как есть.
 */
final class WebpJpeg
{
    public static function is(?string $mime, ?string $name): bool
    {
        return $mime === 'image/webp' || preg_match('/\.webp$/i', (string) $name) === 1;
    }

    /** Путь к JPEG того же кадра; key — постоянное имя в кэше (id медиа, путь), меняется вместе с файлом через mtime. */
    public static function of(string $path, string $key): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $jpeg = Storage::disk('cache')->path('files/jpeg-'.md5($key.'|'.filemtime($path)).'.jpg');
        if (is_file($jpeg) && filesize($jpeg) > 0) {
            return $jpeg;
        }
        @mkdir(dirname($jpeg), 0775, true);
        try {
            $tmp = $jpeg.'.'.getmypid().'.jpg';
            Image::load($path)->format('jpg')->quality(90)->save($tmp);
            rename($tmp, $jpeg);
        } catch (Throwable) {
            @unlink($tmp ?? '');

            return null;
        }

        return is_file($jpeg) ? $jpeg : null;
    }

    /** «договор стр2.webp» → «договор стр2.jpg». */
    public static function name(string $name): string
    {
        return (string) preg_replace('/\.webp$/i', '.jpg', $name);
    }
}
