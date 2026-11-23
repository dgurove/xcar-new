<?php

namespace App\Media;

use App\Support\Pdf;
use App\Support\PdfJoin;
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
     * Парковка: фото и PDF — одним PDF под названием с хвостом ТС (`Vehicle::docLine`). Word сюда не ходит.
     *
     * @param  list<UploadedFile>  $files
     * @return array{pdf: string, name: string, label: string, pages: int} name — имя файла с номером убытка, label — как назвал человек (чип)
     */
    public function make(array $files, string $name, ?\App\Park\Vehicle $vehicle = null): array
    {
        $doc = $this->document($files);
        if ($doc['ext'] !== 'pdf') {
            throw new RuntimeException('нужны фото или PDF');
        }

        return ['pdf' => $doc['body'], 'name' => self::fileName($name, $vehicle?->docLine(), $vehicle?->ref), 'label' => pathinfo(self::fileName($name), PATHINFO_FILENAME), 'pages' => $doc['pages']];
    }

    /**
     * «Приложить» (24.11.2026, владелец: «как в нормальном приложении — из галереи объединять в PDF, документ PDF оставить и
     * переименовать, фотки из документов тоже объединять»): что выбрали, тем порядком — один документ.
     * Один PDF — он же, байт в байт; Word — только один и без другого, как есть; остальное — один PDF: подряд идущие
     * снимки — страницами `park.report.scan`, PDF — своими страницами на своём месте (`PdfJoin`).
     *
     * @param  list<UploadedFile>  $files
     * @return array{body: string, ext: string, pages: int} pages у Word — 0
     */
    public function document(array $files): array
    {
        $files = array_values($files);
        $word = array_filter($files, fn (UploadedFile $f) => self::isWord($f));
        if ($word && count($files) > 1) {
            throw new DocumentRefused('Word прикладывается отдельно: уберите фото или Word');
        }
        if ($word) {
            return ['body' => (string) file_get_contents($files[0]->getRealPath()), 'ext' => strtolower($files[0]->getClientOriginalExtension()) ?: 'docx', 'pages' => 0];
        }
        if (count($files) === 1 && self::isPdf($files[0])) {
            return ['body' => (string) file_get_contents($files[0]->getRealPath()), 'ext' => 'pdf', 'pages' => PdfJoin::pages($files[0]->getRealPath())];
        }
        $parts = [];
        $temp = [];
        $shots = [];
        $pages = 0;
        $flush = function () use (&$shots, &$parts, &$temp) {
            if (! $shots) {
                return;
            }
            $path = tempnam(sys_get_temp_dir(), 'shots-');
            file_put_contents($path, Pdf::render('park.report.scan', ['pages' => $shots], chroot: array_values(array_unique(array_map('dirname', $shots)))));
            $temp[] = $path;
            $parts[] = $path;
            $shots = [];
        };
        try {
            foreach ($files as $file) {
                if (self::isPdf($file)) {
                    $flush();
                    $parts[] = $file->getRealPath();
                    $pages += PdfJoin::pages($file->getRealPath());

                    continue;
                }
                $path = $this->readable($file->getRealPath());
                if ($path !== $file->getRealPath()) {
                    $temp[] = $path;
                }
                $shots[] = $path;
                $pages++;
            }
            $flush();

            return ['body' => PdfJoin::files($parts), 'ext' => 'pdf', 'pages' => $pages];
        } finally {
            array_map('unlink', array_filter($temp, 'is_file'));
        }
    }

    /**
     * Готовый документ «Приложить» — файлом загрузки под названием человека: его берут прежние двери (договор, просьба шага,
     * платёжное поручение за менеджера) так же, как выбранный файл. Хвост — марка и модель (`Offer::title`, владелец: «номера
     * убытков мы не пишем нигде, марку модель там просто и всё»).
     *
     * @param  list<UploadedFile>  $files
     * @return array{file: UploadedFile, name: string, pages: int}
     */
    public function upload(array $files, string $name, ?string $line = null): array
    {
        $doc = $this->document($files);
        $name = self::fileName($name, $line, ext: $doc['ext']);
        $dir = sys_get_temp_dir().'/attach-'.bin2hex(random_bytes(6));
        @mkdir($dir);
        $path = $dir.'/'.$name;
        file_put_contents($path, $doc['body']);
        // Папку уберёт PHP вместе с запросом не сам — после ответа.
        app()->terminating(function () use ($dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        });

        return ['file' => new UploadedFile($path, $name, null, null, true), 'name' => pathinfo($name, PATHINFO_FILENAME), 'pages' => $doc['pages']];
    }

    /**
     * Запрос «Приложить» (`pages[]`, `name`) — готовый документ файлом (`upload`); не собрать (Word с фото, битый PDF) — ошибка
     * поля словами.
     *
     * @return array{file: UploadedFile, name: string, pages: int}
     */
    public function fromRequest(\Illuminate\Http\Request $request, ?string $line = null): array
    {
        $data = $request->validate([
            'pages' => ['required', 'array', 'min:1', 'max:'.self::MAX_PAGES],
            'pages.*' => ['required', 'file', 'max:30720'],
            'name' => ['required', 'string', 'max:120'],
        ], ['name.required' => 'Как назвать документ?', 'pages.required' => 'Выберите фото или файл', 'pages.*.max' => 'Файл больше 30 МБ']);
        try {
            return $this->upload($request->file('pages'), $data['name'], $line);
        } catch (DocumentRefused $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'Не собрался документ: '.$e->getMessage()]);
        }
    }

    public static function isPdf(UploadedFile $file): bool
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) === 'application/pdf';
    }

    public static function isWord(UploadedFile $file): bool
    {
        return in_array(strtolower($file->getClientOriginalExtension()), ['doc', 'docx'], true);
    }

    /**
     * «Акт выдачи» → «Акт выдачи Z691-046-06548-26 Kia Rio 2019 VIN ….pdf» (06.11.2026, Сергей: не только номер
     * убытка — марка, модель, год, VIN, номер; `Vehicle::docLine`): хвостом, если номера убытка в названии ещё нет; без
     * запрещённых знаков (`/` номера — `-`).
     */
    public static function fileName(string $name, ?string $line = null, ?string $ref = null, string $ext = 'pdf'): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', preg_replace('/\.(pdf|docx?)$/iu', '', $name)));
        $has = $ref ? (str_contains($name, $ref) || str_contains($name, str_replace('/', '-', $ref))) : ($line && mb_stripos($name, $line) !== false);
        if ($line && ! $has) {
            $name .= ' '.$line;
        }
        $name = trim((string) preg_replace('/[\\\\\/:*?"<>|]+/u', '-', $name), ' .-');

        return mb_substr($name !== '' ? $name : 'Документ', 0, 180).'.'.$ext;
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
