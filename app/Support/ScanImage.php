<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Снимок или скан страницей для PDF из картинок (dompdf, вид `park.report.scan`): JPEG как есть, PNG и WebP — в JPEG,
 * снимок с телефона — повёрнутым как держали (EXIF), PDF — его листы картинками (`pdftoppm`). HEIC сюда не доходит:
 * `PhotoIngest` перекодирует его при загрузке. Паспорт (`Billing\Documents\PassportPdf`, 05.11.2026) и документ об оплате
 * (`Offers\Documents\PaymentProofPdf`, 10.11.2026).
 */
final class ScanImage
{
    /**
     * Листы файла JPEG-строками: картинка — один, PDF — до `$maxPages` первых.
     *
     * @return list<string>
     */
    public static function pages(Media $media, int $maxPages = 1): array
    {
        $path = $media->getPath();
        if (! is_file($path)) {
            return [];
        }
        if ($media->mime_type === 'application/pdf') {
            return self::pdfPages($path, $maxPages);
        }
        $jpeg = self::jpeg((string) file_get_contents($path));

        return $jpeg === null ? [] : [$jpeg];
    }

    /** Картинка JPEG-строкой с поворотом по EXIF; не картинка — null. */
    public static function jpeg(string $contents): ?string
    {
        $type = @getimagesizefromstring($contents)[2] ?? null;
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

    /** @return list<string> */
    private static function pdfPages(string $path, int $max): array
    {
        $dir = sys_get_temp_dir().'/scan-pdf-'.bin2hex(random_bytes(6));
        @mkdir($dir);
        try {
            Process::timeout(60)->run(['pdftoppm', '-jpeg', '-r', '200', '-f', '1', '-l', (string) max(1, $max), $path, $dir.'/p']);
            $files = glob($dir.'/p-*.jpg') ?: [];
            sort($files, SORT_NATURAL);

            return array_values(array_map('file_get_contents', $files));
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
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
