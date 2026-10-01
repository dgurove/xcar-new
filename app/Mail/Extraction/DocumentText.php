<?php

namespace App\Mail\Extraction;

use App\Mail\Attachment;
use App\Mail\Parts;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Текст файла из письма парковки — то, откуда берётся машина у Альфы Москва: «Заявка на приёмку» и акт приходят
 * сканом, часто боком. Два пути:
 * - `layer` — текстовый слой PDF и текст docx/xlsx (`AttachmentText`), миллисекунды: разбор письма берёт его сам;
 * - `read` — OCR по «✨ Распознать» (`ScanController`): слой, если читается (`AttachmentText::readable`), иначе
 *   скрипт `ocr` (PaddleOCR через RapidOCR, deploy/bin/ocr) по двум первым страницам PDF или по фото. Сам по себе
 *   OCR не запускается: страница на сервере — секунды, и большая часть писем в нём не нуждается.
 * Прочитанное лежит в `cache/scantext/{sha}.txt` навсегда, пустой файл — «текста нет»: одинаковый скан в «ч.1» и в
 * пересылке читается один раз, и разбор письма подхватывает прочитанное «✨» как свой слой. `cache/doctext` — то, что
 * до 01.10.2026 прочёл tesseract сам по каждому письму: разбор письма его ещё берёт (марки цепочек не пропадают),
 * «✨» его не считает прочитанным и читает файл заново.
 */
final class DocumentText
{
    private const DIR = 'scantext';

    private const LEGACY = 'doctext';

    /** Метка «текстового слоя нет» (скан): разбор письма не гоняет pdftotext по тому же файлу при каждой перечитке. */
    private const NO_LAYER = 'nolayer';

    private const MAX_BYTES = 15_000_000;

    private const PAGES = 2;

    /** Картинок на один вызов `ocr`: модели грузятся раз на кусок (~4 с), память не копится. */
    private const CHUNK = 6;

    /** Секунд без ответа `ocr`, после которых он считается зависшим. */
    private const IDLE = 120;

    /** Файл, который «✨» умеет прочитать: PDF или картинка, не встроенная в тело письма. */
    public static function scannable(Attachment $attachment): bool
    {
        $isPdf = $attachment->isPdf() || strtolower(pathinfo((string) $attachment->filename, PATHINFO_EXTENSION)) === 'pdf';

        return ($isPdf || $attachment->isImage()) && ! $attachment->is_inline && (int) $attachment->size <= self::MAX_BYTES;
    }

    /** Прочитанный текст из кеша; null — ещё не читали. */
    public static function cached(Attachment $attachment): ?string
    {
        $disk = Storage::disk(Parts::CACHE_DISK);

        return $disk->exists(self::key($attachment)) ? (string) $disk->get(self::key($attachment)) : null;
    }

    /**
     * Текст без OCR: прочитанное раньше или текстовый слой, если он читается (тогда он ложится в кеш). Файл — только
     * с диска: `mail:read` перечитывает тысячи писем, и ходить за каждым вложением в ящик ему нельзя.
     */
    public static function layer(Attachment $attachment): ?string
    {
        $disk = Storage::disk(Parts::CACHE_DISK);
        $legacy = self::LEGACY.'/'.self::name($attachment);

        return self::cached($attachment) ?? ($disk->exists($legacy) ? (string) $disk->get($legacy) : null) ?? self::text($attachment);
    }

    /**
     * Текстовый слой файла, если он читается; ложится в кеш. Нечитаемый слой помечается и больше не достаётся.
     * Без `$fetch` — только файл с диска (разбор письма), с ним — и из ящика («✨»: человек ждёт этот файл).
     */
    private static function text(Attachment $attachment, bool $fetch = false): ?string
    {
        $disk = Storage::disk(Parts::CACHE_DISK);
        $none = self::NO_LAYER.'/'.self::name($attachment);
        if ($attachment->is_inline || $disk->exists($none) || (! $fetch && ! $attachment->isOnDisk()) || ! ($path = $attachment->file())) {
            return null;
        }
        $text = AttachmentText::of($path, (string) $attachment->filename, $attachment->mime);
        if ($text === null || ! AttachmentText::readable($text)) {
            $disk->put($none, '');

            return null;
        }
        self::put($attachment, $text);

        return $text;
    }

    /**
     * Прочитать пачку: прочитанное — сразу, слой — если читается, остальное — через `ocr` кусками по CHUNK картинок. `$done($attachment, $text)` зовётся по каждому файлу, как только он готов. Ошибка — пустой
     * текст в кеш, без повторов. `$timeout` — меньше таймаута задачи: иначе её убьют раньше, чем сработает `finally`.
     *
     * @param  iterable<Attachment>  $attachments
     */
    public static function read(iterable $attachments, ?Closure $done = null, int $timeout = 600): void
    {
        $done ??= fn () => null;
        $dir = sys_get_temp_dir().'/xcar-ocr-'.bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);
        /** @var array<string, Attachment> $owner страница или фото → вложение */
        $owner = [];
        /** @var array<int, array<string, ?string>> $pages вложение → его файлы для ocr и их текст */
        $pages = [];
        try {
            foreach ($attachments as $a) {
                if (($text = self::cached($a)) !== null || ($text = self::text($a, true)) !== null) {
                    $done($a, $text);

                    continue;
                }
                $files = self::images($a, $dir);
                if (! $files) {
                    self::put($a, '');
                    $done($a, '');

                    continue;
                }
                foreach ($files as $file) {
                    $owner[$file] = $a;
                    $pages[$a->id][$file] = null;
                }
            }
            if (! $owner) {
                return;
            }
            $seen = [];
            $take = function (string $line) use (&$owner, &$pages, &$seen, $done) {
                $row = json_decode($line, true);
                $a = is_array($row) ? ($owner[$row['file'] ?? ''] ?? null) : null;
                if (! $a || ! isset($pages[$a->id])) {
                    return;
                }
                $seen[$row['file']] = true;
                $pages[$a->id][$row['file']] = (string) ($row['text'] ?? '');
                if (! in_array(null, $pages[$a->id], true)) {
                    $text = trim(implode("\n\f\n", $pages[$a->id]));
                    self::put($a, $text);
                    unset($pages[$a->id]);
                    $done($a, $text);
                }
            };
            // Кусками по CHUNK картинок: на длинной пачке ocr падал (сигнал 11, копится память), по одному — ни разу.
            // Кусок упал — его непрочитанное дочитывается по одному; что не прочлось и так — пусто в finally.
            $deadline = time() + $timeout;
            foreach (array_chunk(array_keys($owner), self::CHUNK) as $chunk) {
                if (! self::run($chunk, $deadline, $take)) {
                    foreach (array_filter($chunk, fn ($file) => ! isset($seen[$file])) as $file) {
                        self::run([$file], $deadline, $take);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('Почта: документы не прочитаны', ['error' => $e->getMessage()]);
        } finally {
            // Чего ocr не вернул (упал, не уложился) — в кеш не кладётся: это сбой, а не «текста нет». Окно покажет
            // «не прочитан» с «Повторить», задача снимет метку и окно отпустит.
            foreach (array_keys($pages) as $id) {
                $done($owner[array_key_first($pages[$id])], null);
            }
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    /** Один вызов `ocr` по картинкам, ответ — построчно в `$take` по мере готовности. false — упал или вышло время. */
    private static function run(array $files, int $deadline, Closure $take): bool
    {
        if ($deadline - time() < 10) {
            return false;
        }
        $buffer = '';
        // nice: сайт и почта на двух ядрах впереди OCR. idleTimeout: страница на проде — до ~10 с; молчит две минуты —
        // завис, кусок дочитается по одному.
        $process = Process::timeout($deadline - time())->idleTimeout(self::IDLE)->start(['nice', '-n', '10', config('xcar.ocr', 'ocr'), ...$files], function (string $type, string $output) use (&$buffer, $take) {
            if ($type !== 'out') {
                return;
            }
            $buffer .= $output;
            while (($n = strpos($buffer, "\n")) !== false) {
                $take(substr($buffer, 0, $n));
                $buffer = substr($buffer, $n + 1);
            }
        });
        try {
            $result = $process->wait();
        } catch (Throwable $e) {
            // Упал (сигнал) или завис: в stderr — стек faulthandler, по нему видно, где.
            Log::warning('Почта: ocr упал', ['files' => count($files), 'error' => $e->getMessage(), 'stderr' => mb_substr($process->errorOutput(), -1500)]);

            return false;
        }
        if (! $result->successful()) {
            Log::warning('Почта: ocr упал', ['files' => count($files), 'error' => mb_substr($result->errorOutput(), -300)]);
        }

        return $result->successful();
    }

    /** Картинки для ocr: первые страницы PDF (имя «page-…» — скрипт ставит их прямо) или само фото. @return list<string> */
    private static function images(Attachment $a, string $dir): array
    {
        $path = $a->file();
        if (! $path) {
            return [];
        }
        if (! $a->isImage()) {
            Process::timeout(60)->run(['pdftoppm', '-scale-to', '2000', '-png', '-l', (string) self::PAGES, $path, $dir.'/page-'.$a->id]);
            $files = glob($dir.'/page-'.$a->id.'-*.png') ?: [];
            sort($files);

            return $files;
        }

        return [$path];
    }

    private static function put(Attachment $attachment, string $text): void
    {
        Storage::disk(Parts::CACHE_DISK)->put(self::key($attachment), $text);
    }

    private static function key(Attachment $attachment): string
    {
        return self::DIR.'/'.self::name($attachment);
    }

    private static function name(Attachment $attachment): string
    {
        return ($attachment->blob_sha ?: 'a'.$attachment->id).'.txt';
    }
}
