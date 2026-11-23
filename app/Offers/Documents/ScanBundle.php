<?php

namespace App\Offers\Documents;

use App\Support\Pdf;
use App\Support\PdfJoin;
use App\Support\ScanImage;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Файлы одним PDF — документ об оплате (`PaymentProofPdf`, 10.11.2026) и подписанный договор (`SignedContractPdf`, 17.11.2026):
 * снимки и сканы — по листу A4, как держали телефон (`ScanImage`); PDF — подклеиваются целиком (`pdfunite`, нет его — листами-
 * картинками). Один PDF — он и есть документ.
 */
final class ScanBundle
{
    /** @param  Collection<int, Media>  $files  null — нечего собрать. */
    public static function pdf(Collection $files): ?string
    {
        $pdfs = $files->filter(fn (Media $m) => $m->mime_type === 'application/pdf' && is_file($m->getPath()))->values();
        if ($files->count() === 1 && $pdfs->count() === 1) {
            return (string) file_get_contents($pdfs->first()->getPath());
        }
        $unite = PdfJoin::available();
        $pages = [];
        foreach ($files as $m) {
            if ($m->mime_type !== 'application/pdf' || ! $unite) {
                $pages = [...$pages, ...ScanImage::pages($m, 20)];
            }
        }
        $images = $pages ? Pdf::render('park.report.scan', ['pages' => array_map(fn ($j) => 'data:image/jpeg;base64,'.base64_encode($j), $pages)]) : null;
        if (! $unite || $pdfs->isEmpty()) {
            return $images;
        }
        if ($images === null && $pdfs->count() === 1) {
            return (string) file_get_contents($pdfs->first()->getPath());
        }

        $dir = sys_get_temp_dir().'/scan-pdf-'.bin2hex(random_bytes(6));
        @mkdir($dir);
        try {
            $parts = $pdfs->map(fn (Media $m) => $m->getPath())->all();
            if ($images !== null) {
                file_put_contents($dir.'/images.pdf', $images);
                array_unshift($parts, $dir.'/images.pdf');
            }
            try {
                return PdfJoin::files($parts);
            } catch (\RuntimeException) {
                return $images;
            }
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }
}
