<?php

namespace App\Support;

use App\Mail\Attachment;
use App\Mail\Extraction\ArchivePhotoExtractor;
use App\Mail\Extraction\AttachmentClassifier;
use App\Mail\Message;
use App\Media\MediaUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Документы для шторки (x-ui.docs): вложения писем, файлы медиатеки, само письмо, фото. Каждый — ссылка
 * `a[data-doc]` (x-ui.doc), тип решает, чем рисовать: pdf — pdf.js, image — картинкой, sheet (и csv), word и text — HTML, video — плеером
 * с сервера (`?preview=1`, OfficePreview), file — карточкой со «Скачать», letter — тело письма, photos — сетка.
 */
final class Docs
{
    public static function type(?string $mime, ?string $name): string
    {
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        return match (true) {
            $mime === 'application/pdf' || $ext === 'pdf' => 'pdf',
            (str_starts_with((string) $mime, 'image/') && $mime !== 'image/svg+xml') || in_array($ext, ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'heic', 'heif'], true) => 'image',
            in_array($ext, ['xlsx', 'xlsm', 'csv'], true) => 'sheet',
            str_starts_with((string) $mime, 'video/') || in_array($ext, ['mp4', 'mov', 'm4v', 'webm'], true) => 'video',
            $ext === 'docx' => 'word',
            $ext === 'txt' || $mime === 'text/plain' => 'text',
            default => 'file',
        };
    }

    /** Имя для сравнения: медиатека меняет пробелы и знаки на дефисы, «Акт пп.pdf» и «Акт-пп.pdf» — один файл. */
    public static function norm(string $name): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $name));
    }

    /** Подпись вкладки — имя файла без расширения (медиатека пишет пробелы дефисами: «Согаз-заявка» — словами; дефис в номере «473697-26» остаётся). */
    public static function label(string $name): string
    {
        return trim((string) preg_replace(['/_+/', '/(?<!\d)-|-(?!\d)/'], ' ', pathinfo($name, PATHINFO_FILENAME))) ?: $name;
    }

    /** @return array<string, mixed> */
    public static function attachment(Attachment $a, string $base): array
    {
        $type = self::type($a->mime, $a->filename);
        $url = "{$base}/attachments/{$a->id}";

        return ['url' => $url, 'type' => $type, 'name' => $a->filename, 'label' => self::label($a->filename), 'src' => $type === 'image' ? "{$url}?large=1" : null];
    }

    /** @return array<string, mixed> */
    public static function media(Media $m): array
    {
        // HEIC с айфона Chrome не показывает — сервер отдаёт JPEG (`FileController`, `?jpeg=1`).
        $heic = preg_match('/\.hei[cf]$/i', $m->file_name) === 1 || in_array($m->mime_type, ['image/heic', 'image/heif'], true);

        return ['url' => "/files/{$m->id}", 'type' => self::type($m->mime_type, $m->file_name), 'name' => $m->file_name, 'src' => $heic ? "/files/{$m->id}?jpeg=1" : null,
            'label' => $m->name ?: self::label($m->file_name)];
    }

    /** @return array<string, mixed> */
    public static function letter(Message $m, string $base, ?string $thread = null): array
    {
        return ['url' => "{$base}/messages/{$m->id}/body", 'type' => 'letter', 'name' => (string) $m->subject, 'label' => 'Письмо', 'thread' => $thread];
    }

    /**
     * Записи архива письма, если он уже на диске (открывать ящик ради списка страница не будет); сутки в кэше.
     *
     * @return list<array{index: int, name: string, size: int}>
     */
    private static function archive(Attachment $a): array
    {
        if (! ArchivePhotoExtractor::isArchive($a->mime) && ! ArchivePhotoExtractor::isArchiveName((string) $a->filename)) {
            return [];
        }
        if (! $a->isOnDisk()) {
            return [];
        }

        return Cache::remember("mail:archive:{$a->id}", 86400, fn () => app(ArchivePhotoExtractor::class)->listFile((string) $a->file()));
    }

    /**
     * Фото сеткой: вложения-картинки писем или кадры медиатеки.
     *
     * @param  Collection<int, Attachment|Media|array{t: string, s: string}>  $items
     * @return array<string, mixed>|null
     */
    public static function photos(Collection $items, string $base = '/mail'): ?array
    {
        if ($items->isEmpty()) {
            return null;
        }
        $list = $items->map(fn ($p) => match (true) {
            $p instanceof Media => ['t' => MediaUrl::for($p, 'w320'), 's' => MediaUrl::for($p)],
            $p instanceof Attachment => ['t' => "{$base}/attachments/{$p->id}?thumb=1", 's' => "{$base}/attachments/{$p->id}?large=1"],
            default => $p,
        })->values();

        return ['url' => '#photos', 'type' => 'photos', 'name' => 'Фото', 'label' => 'Фото '.$list->count(), 'photos' => $list->all()];
    }

    /**
     * Документы писем без фото: вложения не встроенные и не картинки (картинки идут во «Фото»); файлы старых и
     * замороженных писем на диске нет — шторка тянет их из ящика, как ссылка в ленте. Сканы-картинки с видом
     * в имени (СТС, акт) — документы. Одно и то же вложение в нескольких письмах цепочки (пересылка, «ч.2») — один
     * раз: ключ — имя без регистра и размер, место — первого, файл — последнего письма (у него чаще есть на диске).
     *
     * @param  iterable<Message>  $messages
     * @return array{docs: list<array<string, mixed>>, photos: Collection<int, Attachment>}
     */
    public static function fromLetters(iterable $messages, string $base): array
    {
        $docs = [];
        $photos = [];
        foreach ($messages as $m) {
            foreach ($m->files() as $a) {
                $key = $a->fileKey();
                if ($a->isPhoto()) {
                    $photos[$key] = $a;
                } elseif ($entries = self::archive($a)) {
                    // Архив раскрыт: каждый файл — своей вкладкой, кадры — во «Фото».
                    $shots = 0;
                    foreach ($entries as $e) {
                        $url = "{$base}/attachments/{$a->id}?entry={$e['index']}";
                        $name = basename($e['name']);
                        $type = self::type(null, $name);
                        $inner = $key.'|'.$e['name'];
                        // Кадр из архива каждый раз достаётся из него целиком — в шторку не больше 60 штук.
                        if ($type === 'image' && AttachmentClassifier::kindOf($name) === null) {
                            $shots++ < 60 && $photos[$inner] = ['t' => $url, 's' => $url];
                        } else {
                            $docs[$inner] = ['url' => $url, 'type' => $type, 'name' => $name, 'label' => self::label($name), 'src' => null];
                        }
                    }
                } else {
                    $docs[$key] = self::attachment($a, $base);
                }
            }
        }
        $docs = array_values($docs);
        $photos = collect(array_values($photos));

        return ['docs' => $docs, 'photos' => $photos];
    }
}
