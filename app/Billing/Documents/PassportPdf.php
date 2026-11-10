<?php

namespace App\Billing\Documents;

use App\Billing\Party;
use App\Support\Pdf;
use App\Support\ScanImage;
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
        return ScanImage::pages($media)[0] ?? null;
    }
}
