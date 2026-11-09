<?php

namespace App\Billing\Documents;

use App\Billing\Party;
use App\Support\Pdf;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Скан паспорта покупателя одним PDF (05.11.2026, владелец: «должен pdf файл со сканами формировать и прикреплять», «данные
 * покупателя невозможно посмотреть и скачать в админке»): разворот с фото и прописка — по листу A4 (вид `park.report.scan`).
 * Страница сканом PDF — её первый лист картинкой. Письмо поставщику с реквизитами уходит с ним, в CRM — «Паспорт, PDF».
 */
final class PassportPdf
{
    /** null — ни одной страницы скана, которую можно нарисовать. */
    public static function render(Party $party): ?string
    {
        $pages = [];
        foreach (array_keys(Party::PASSPORT_PAGES) as $page) {
            if (($media = $party->passportScan($page)) && ($jpeg = self::image($media))) {
                $pages[] = 'data:image/jpeg;base64,'.base64_encode($jpeg);
            }
        }

        return $pages ? Pdf::render('park.report.scan', ['pages' => $pages]) : null;
    }

    public static function fileName(Party $party): string
    {
        return 'Паспорт '.Str::of((string) $party->name)->squish()->replaceMatches('/[\\\\\/:*?"<>|]+/u', '').'.pdf';
    }

    /** Картинка страницы для dompdf: JPEG и PNG как есть (PNG — в JPEG), PDF — первый лист, остальное (HEIC) — нет. */
    private static function image(Media $media): ?string
    {
        $path = $media->getPath();
        if (! is_file($path)) {
            return null;
        }
        if ($media->mime_type === 'application/pdf') {
            $dir = sys_get_temp_dir().'/passport-pdf-'.bin2hex(random_bytes(6));
            @mkdir($dir);
            try {
                Process::timeout(60)->run(['pdftoppm', '-jpeg', '-r', '200', '-f', '1', '-l', '1', $path, $dir.'/p']);
                $file = (glob($dir.'/p-*.jpg') ?: [null])[0];

                return $file ? file_get_contents($file) : null;
            } finally {
                array_map('unlink', glob($dir.'/*') ?: []);
                @rmdir($dir);
            }
        }
        $type = @getimagesize($path)[2] ?? null;
        $contents = (string) file_get_contents($path);
        // Страница — как держали телефон (09.11.2026, владелец: «пдф с обеими страницами паспорта с корректными
        // ориентациями»): снимок с телефона лежит боком, а «как повернуть» — в EXIF; dompdf его не читает.
        $turn = $type === IMAGETYPE_JPEG ? self::exifTurn($contents) : 0;
        if ($type === IMAGETYPE_JPEG && $turn === 0) {
            return $contents;
        }
        if (in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) && ($img = @imagecreatefromstring($contents))) {
            if ($turn !== 0 && ($rotated = imagerotate($img, $turn, 0))) {
                $img = $rotated;
            }
            ob_start();
            imagejpeg($img, null, 90);

            return (string) ob_get_clean();
        }

        return null;
    }

    /** Поворот из EXIF (градусы для `imagerotate`, против часовой): 3 — 180, 6 — на 90 по часовой, 8 — на 90 против. */
    private static function exifTurn(string $jpeg): int
    {
        if (! function_exists('exif_read_data')) {
            return 0;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($jpeg));

        return match ((int) ($exif['Orientation'] ?? 0)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
    }
}
