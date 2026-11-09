<?php

namespace App\Mail;

use App\Media\PhotoIngest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Картинки во вложениях письма — только JPEG и PNG (09.11.2026: Альфа не открыла сканы паспорта в реквизитах по
 * 0790/046/11079/26 — ушли `.webp`, так приложение сжимает фото при загрузке; тем же видом ушли фото выдачи в четырёх
 * письмах Колбасиной). WebP и AVIF перекодирует GD, HEIC — `heic2jpg` (`PhotoIngest::toJpeg`); имя — то же с `.jpg`.
 * Не вышло — файл уходит как был: письмо без вложения хуже.
 */
final class OutboxImage
{
    private const ODD = ['webp', 'avif', 'heic', 'heif'];

    /** Путь в outbox: новый JPEG вместо странной картинки, иначе тот же. */
    public static function normalize(string $path): string
    {
        $disk = Storage::disk(Parts::CACHE_DISK);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = (string) $disk->mimeType($path);
        $odd = in_array($ext, self::ODD, true) || in_array($mime, ['image/webp', 'image/avif', 'image/heic', 'image/heif'], true);
        if (! $odd) {
            return $path;
        }
        $source = $disk->path($path);
        try {
            $jpg = self::jpeg($source, $ext, $mime);
            if ($jpg === null) {
                return $path;
            }
            $name = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', substr(basename($path), 37)).'.jpg';
            $new = 'outbox/'.Str::uuid().'-'.$name;
            $disk->put($new, (string) file_get_contents($jpg));
            @unlink($jpg);
            $disk->delete($path);

            return $new;
        } catch (Throwable $e) {
            report($e);

            return $path;
        }
    }

    /** JPEG во временном файле или null. */
    private static function jpeg(string $source, string $ext, string $mime): ?string
    {
        $ingest = app(PhotoIngest::class);
        if (in_array($ext, ['heic', 'heif'], true) || in_array($mime, ['image/heic', 'image/heif'], true) || $ingest->isHeic($source)) {
            return $ingest->toJpeg($source);
        }
        $image = match (true) {
            ($ext === 'avif' || $mime === 'image/avif') && function_exists('imagecreatefromavif') => @imagecreatefromavif($source),
            function_exists('imagecreatefromwebp') => @imagecreatefromwebp($source),
            default => false,
        };
        if (! $image) {
            return null;
        }
        // Прозрачность — на белом: у JPEG её нет, а чёрный фон на скане паспорта хуже.
        $w = imagesx($image);
        $h = imagesy($image);
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $w, $h);
        $out = tempnam(sys_get_temp_dir(), 'out-').'.jpg';
        imagejpeg($canvas, $out, 90);
        imagedestroy($image);
        imagedestroy($canvas);

        return is_file($out) && filesize($out) > 0 ? $out : null;
    }
}
