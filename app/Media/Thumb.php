<?php

namespace App\Media;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Миниатюра файла, посчитанная раз и лежащая в `cache` (`storage:gc` чистит `mail/` по сроку): картинка — ужатая,
 * PDF — первая страница, поставленная прямо моделью ориентации `ocr` (скан Альфы лежит боком). Одна на вложения
 * писем (`MailController::attachment`) и документы с закрытого диска (`FileController`) — плитки «✨ Распознать».
 */
final class Thumb
{
    /** Путь к готовой миниатюре; null — не вышло, отдать файл как есть или значок. `$name` — имя в `cache/mail`. */
    public static function of(string $file, bool $pdf, string $name, int $size = 320): ?string
    {
        $small = Storage::disk('cache')->path('mail/'.$name.'.webp');
        if (is_file($small)) {
            return $small;
        }
        @mkdir(dirname($small), 0775, true);
        $pdf ? self::page($file, $small) : self::shrink($file, $small, $size);

        return is_file($small) ? $small : null;
    }

    private static function shrink(string $file, string $small, int $size): void
    {
        try {
            rename(app(PhotoIngest::class)->shrink($file, $size), $small);
        } catch (Throwable) {
            // Не пережалось (битый файл) — отдаётся как есть.
        }
    }

    private static function page(string $file, string $small): void
    {
        $page = sys_get_temp_dir().'/xcar-thumb-'.bin2hex(random_bytes(6));
        try {
            Process::timeout(20)->run(['pdftoppm', '-png', '-singlefile', '-f', '1', '-l', '1', '-scale-to', '640', $file, $page]);
            $angle = (int) trim(Process::timeout(20)->run([config('xcar.ocr', 'ocr'), '--angle', $page.'.png'])->output());
            if ($angle && ($gd = @imagecreatefrompng($page.'.png')) && ($turned = imagerotate($gd, $angle, 0))) {
                imagepng($turned, $page.'.png', 1);
            }
            if (is_file($page.'.png')) {
                rename(app(PhotoIngest::class)->shrink($page.'.png', 320), $small);
            }
        } catch (Throwable) {
            // Не отрисовалась — плитка останется значком PDF.
        } finally {
            @unlink($page.'.png');
        }
    }
}
