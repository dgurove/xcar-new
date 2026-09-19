<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Один dompdf на все документы: шрифт Onest файлами из проекта, кэш шрифтов и временные
 * файлы — в storage (vendor на сервере не для записи), картинки только из разрешённых папок.
 */
final class Pdf
{
    /** @param  list<string>  $chroot  откуда документу можно брать картинки, кроме шрифтов и `public/images` */
    public static function make(string $view, array $data, string $orientation = 'portrait', array $chroot = []): Dompdf
    {
        $dir = storage_path('app/private/dompdf');
        @mkdir($dir, 0775, true);
        $options = (new Options)->setIsRemoteEnabled(false)->setDefaultFont('Onest')->setDefaultPaperSize('a4')->setDefaultPaperOrientation($orientation)
            ->setTempDir($dir)->setFontCache($dir)->setChroot([$dir, resource_path('fonts/pdf'), public_path('images'), ...$chroot]);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view($view, $data + ['pdf' => true])->render());

        return $pdf;
    }

    public static function render(string $view, array $data, string $orientation = 'portrait', array $chroot = []): string
    {
        $pdf = self::make($view, $data, $orientation, $chroot);
        $pdf->render();

        return $pdf->output();
    }
}
