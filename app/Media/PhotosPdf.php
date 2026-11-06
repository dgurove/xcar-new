<?php

namespace App\Media;

use App\Support\Pdf;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Spatie\Image\Image;
use Throwable;

/**
 * Документ из фото одним PDF (06.11.2026, Сергей: «проще сфоткать документ, чем сканить»): снимки с камеры или из
 * галереи — по странице A4 на снимок (`park.report.scan`), в порядке выбора, под названием, что дал человек. Акт приёма в
 * отчёте вендору (`ReportController::pdf`) и вложение письма о выдаче (`MailController::filePdf`).
 */
final class PhotosPdf
{
    public const MAX_PAGES = 30;

    public function __construct(private PhotoIngest $ingest) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return array{pdf: string, name: string, label: string, pages: int} name — имя файла с номером убытка, label — как назвал человек (чип)
     */
    public function make(array $files, string $name, ?string $ref = null): array
    {
        $pages = [];
        $temp = [];
        try {
            foreach ($files as $i => $file) {
                $path = $this->readable($file->getRealPath());
                if ($path !== $file->getRealPath()) {
                    $temp[] = $path;
                }
                $pages[] = $path;
            }
            $pdf = Pdf::render('park.report.scan', ['pages' => $pages], chroot: array_values(array_unique(array_map('dirname', $pages))));
        } finally {
            array_map('unlink', array_filter($temp, 'is_file'));
        }

        return ['pdf' => $pdf, 'name' => self::fileName($name, $ref), 'label' => pathinfo(self::fileName($name), PATHINFO_FILENAME), 'pages' => count($pages)];
    }

    /** «Акт выдачи» → «Акт выдачи 0760-046-02677-26.pdf»: номер убытка хвостом, если его ещё нет; без запрещённых знаков. */
    public static function fileName(string $name, ?string $ref = null): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', preg_replace('/\.pdf$/iu', '', $name)));
        $tail = $ref ? str_replace('/', '-', trim($ref)) : '';
        if ($tail !== '' && ! str_contains($name, $tail) && ! str_contains($name, trim((string) $ref))) {
            $name .= ' '.$tail;
        }
        $name = trim((string) preg_replace('/[\\\\\/:*?"<>|]+/u', '-', $name), ' .-');

        return mb_substr($name !== '' ? $name : 'Документ', 0, 120).'.pdf';
    }

    /**
     * Снимок — картинкой, которую прочтёт dompdf (JPEG, PNG, GIF): HEIC с Android (телефон его не ужал) и прочее — в JPEG;
     * не читается вовсе — ошибка сразу, а не пустая страница у вендора. Не картинка — как есть (это решает вызывающий).
     */
    public function readable(string $path): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! str_starts_with($mime, 'image/') && ! $this->ingest->isHeic($path)) {
            return $path;
        }
        if ($this->ingest->isHeic($path)) {
            return $this->ingest->toJpeg($path);
        }
        $type = @getimagesize($path)[2] ?? null;
        if (in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
            return $path;
        }
        $base = tempnam(sys_get_temp_dir(), 'scan');
        $jpg = $base.'.jpg';
        @unlink($base);
        try {
            Image::useImageDriver('gd')->loadFile($path)->quality(85)->save($jpg);
        } catch (Throwable) {
            @unlink($jpg);
            throw new RuntimeException('снимок не читается, снимите заново');
        }

        return $jpg;
    }
}
