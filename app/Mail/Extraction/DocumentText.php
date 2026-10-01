<?php

namespace App\Mail\Extraction;

use App\Mail\Attachment;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Parts;
use App\Mail\Scope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Текст PDF из письма парковки — то, откуда берётся машина у Альфы Москва: «Заявка на приёмку» и акт приходят
 * сканом (Canon без текстового слоя или со слоем-мусором), часто боком. Текстовый слой берётся, если он читается
 * (`AttachmentText::readable`), иначе OCR: `pdftoppm` 300 dpi первых двух страниц, поворот по OSD tesseract
 * (поворачивает GD), `tesseract rus+eng --psm 6` с сохранёнными пробелами — строка таблицы выходит строкой.
 * Прочитанное лежит в `cache/doctext/{sha}.txt` навсегда, пустой файл — «текста нет»: одинаковый скан в «ч.1» и
 * в пересылке читается один раз. При приёме письма и в `mail:read` читается только кеш (`cached`), сам OCR —
 * в очереди (`Jobs\ReadDocuments`): страница стоит секунды, приём почты ждать не должен.
 */
final class DocumentText
{
    private const DIR = 'doctext';

    private const MAX_BYTES = 15_000_000;

    /** PDF вендора в письме парковки — кандидат на чтение. */
    public static function wanted(Attachment $attachment): bool
    {
        $isPdf = $attachment->mime === 'application/pdf' || strtolower(pathinfo((string) $attachment->filename, PATHINFO_EXTENSION)) === 'pdf';

        return $isPdf && ! $attachment->is_inline && (int) $attachment->size <= self::MAX_BYTES;
    }

    /** Прочитанный текст из кеша; null — ещё не читали. */
    public static function cached(Attachment $attachment): ?string
    {
        $disk = Storage::disk(Parts::CACHE_DISK);

        return $disk->exists(self::key($attachment)) ? (string) $disk->get(self::key($attachment)) : null;
    }

    /**
     * Документы письма, которые ещё не читали: входящее письмо парковки, не замороженное и не в ветке заведённой ТС —
     * у неё поля уже вписаны людьми, OCR ей ничего не даст. @return Collection<int, Attachment>
     */
    public static function pending(Message $message): Collection
    {
        $message->loadMissing(['account', 'attachments', 'thread']);
        if ($message->account?->scope !== Scope::Park || $message->direction !== Direction::In || $message->frozen_at || $message->thread?->vehicle_id) {
            return collect();
        }

        return $message->attachments->filter(fn (Attachment $a) => self::wanted($a) && self::cached($a) === null)->values();
    }

    /** Прочитать и положить в кеш: текстовый слой, если читается, иначе OCR. Ошибка — пустой текст, без повторов. */
    public static function read(Attachment $attachment): string
    {
        $text = '';
        try {
            $path = $attachment->file();
            if ($path) {
                $layer = AttachmentText::of($path, (string) $attachment->filename, $attachment->mime);
                $text = $layer !== null && AttachmentText::readable($layer) ? $layer : self::ocr($path);
            }
        } catch (Throwable $e) {
            Log::warning('Почта: документ не прочитан', ['attachment' => $attachment->id, 'error' => $e->getMessage()]);
        }
        Storage::disk(Parts::CACHE_DISK)->put(self::key($attachment), $text);

        return $text;
    }

    private static function key(Attachment $attachment): string
    {
        return self::DIR.'/'.($attachment->blob_sha ?: 'a'.$attachment->id).'.txt';
    }

    private static function ocr(string $pdf): string
    {
        $dir = sys_get_temp_dir().'/xcar-ocr-'.bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);
        // Сервер слабый: tesseract не должен занимать оба ядра.
        $env = ['OMP_THREAD_LIMIT' => '1'];
        try {
            Process::timeout(60)->run(['pdftoppm', '-r', '300', '-png', '-l', '2', $pdf, $dir.'/p']);
            $pages = glob($dir.'/p*.png') ?: [];
            sort($pages);
            $text = [];
            foreach ($pages as $png) {
                self::upright($png, $env);
                $result = Process::timeout(120)->env($env)->run(['tesseract', $png, '-', '-l', 'rus+eng', '--psm', '6', '-c', 'preserve_interword_spaces=1']);
                if ($result->successful()) {
                    $text[] = $result->output();
                }
            }

            return trim(implode("\n\f\n", $text));
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    /** Повернуть страницу по OSD: «Rotate: 90» — по часовой; GD крутит против, отсюда 360 − угол. */
    private static function upright(string $png, array $env): void
    {
        $osd = Process::timeout(60)->env($env)->run(['tesseract', $png, '-', '--psm', '0']);
        if (! preg_match('/^Rotate:\s*(90|180|270)\b/m', $osd->output(), $m) || ! ($image = @imagecreatefrompng($png))) {
            return;
        }
        $rotated = imagerotate($image, 360 - (int) $m[1], 0);
        if ($rotated) {
            imagepng($rotated, $png, 1);
        }
    }
}
