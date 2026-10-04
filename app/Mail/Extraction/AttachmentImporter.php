<?php

namespace App\Mail\Extraction;

use App\Mail\Attachment;
use App\Mail\Message;
use App\Media\PhotoIngest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\HasMedia;
use Throwable;

/**
 * Вложения письма → медиатека машины: документы как есть, фото через приём,
 * архивы распаковываются, листы со снимками режутся. Что уже лежит (по
 * отпечатку `sha` исходника) — пропускается: повторное письмо, ручная
 * загрузка того же файла или второй запуск дублей не дают.
 */
final class AttachmentImporter
{
    public function __construct(private AttachmentClassifier $classifier, private ArchivePhotoExtractor $archives, private PageScanCropper $cropper, private PhotoIngest $photos) {}

    /**
     * @param  array|callable(Attachment): array  $photoProperties  свойства кадра: одни на всех (оффер) либо по письму
     *                                                              вложения (стоянка: чьё письмо — такая и стадия)
     * @return array{photos: int, documents: int} сколько добавлено
     */
    public function import(HasMedia $model, Collection $attachments, string $photosCollection, string $documentsCollection, array|callable $photoProperties = [], ?callable $progress = null): array
    {
        $added = ['photos' => 0, 'documents' => 0];
        if ($attachments->isEmpty()) {
            return $added;
        }
        $classified = $this->classifier->classify($attachments);
        foreach ($classified['documents'] as $document) {
            $added['documents'] += (int) $this->addDocument($model, $documentsCollection, $document);
        }
        $propertiesOf = is_array($photoProperties) ? fn () => $photoProperties : $photoProperties;
        $sources = [];
        foreach ($classified['photos'] as $photo) {
            if (($contents = $photo->contents()) !== null) {
                $sources[] = ['name' => $photo->filename, 'contents' => $contents, 'properties' => $propertiesOf($photo)];
            }
        }
        foreach ($classified['archives'] as $i => $archive) {
            $progress && $progress('Распаковываем архив', $i, count($classified['archives']));
            // Архив разворачивается целиком: кадры — к фото, остальное (PDF, СТС, Excel) — к документам;
            // не открылся (битый, с паролем) — лежит документом, как пришёл.
            $extracted = $this->archives->extractFiles($archive);
            if (! $extracted) {
                $added['documents'] += (int) $this->addDocument($model, $documentsCollection, $archive);

                continue;
            }
            foreach ($extracted as $file) {
                // Кадры из архива и вырезки из листа — свойства того вложения, в котором они приехали.
                if ($file['photo'] && ! $this->classifier->looksLikeDocument(mb_strtolower($file['name']))) {
                    $sources[] = ['name' => $file['name'], 'contents' => $file['contents'], 'properties' => $propertiesOf($archive)];
                } else {
                    $added['documents'] += (int) $this->addDocumentFile($model, $documentsCollection, $file['name'], $file['contents']);
                }
            }
        }
        foreach ($sources as $i => $photo) {
            $progress && $progress('Разбираем фотографии', $i, count($sources));
            $this->guard(function () use ($model, $photosCollection, $photo, &$added) {
                $cropped = $this->cropper->crop($photo['contents']);
                if (! $cropped) {
                    $added['photos'] += (int) $this->addPhoto($model, $photosCollection, $photo['contents'], $photo['name'], $photo['properties']);

                    return;
                }
                $base = pathinfo($photo['name'], PATHINFO_FILENAME);
                foreach ($cropped as $n => $contents) {
                    $added['photos'] += (int) $this->addPhoto($model, $photosCollection, $contents, $base.(count($cropped) > 1 ? '-'.($n + 1) : '').'.jpg', $photo['properties']);
                }
            });
        }

        return $added;
    }

    /**
     * Только документы, которые уже лежат у нас (закреплены при приходе письма): их кладут сразу при «Завести», пока
     * человек открывает редактор. Документ без файла на диске, архивы и фото — работа `ImportThreadFiles`.
     *
     * @return int сколько добавлено
     */
    public function importDocuments(HasMedia $model, Collection $attachments, string $collection): int
    {
        $added = 0;
        foreach ($this->classifier->classify($attachments)['documents'] as $document) {
            if ($document->isOnDisk()) {
                $added += (int) $this->addDocument($model, $collection, $document);
            }
        }

        return $added;
    }

    /** Сколько фото и архивов ветки ещё прикрепит `ImportThreadFiles` — заглушки в ряду фото, пока она не дошла. */
    public function photosToCome(Collection $attachments): int
    {
        $classified = $this->classifier->classify($attachments);

        return count($classified['photos']) + count($classified['archives']);
    }

    /**
     * Архив с диска (брошен руками в «Фотографии»): кадры — к фото, остальное (PDF, СТС, Excel) — к документам, как архив
     * письма; что уже лежит — пропускается. Не открылся (битый, с паролем) — null.
     *
     * @return array{photos: int, documents: int}|null
     */
    public function importArchive(HasMedia $model, string $path, string $photosCollection, string $documentsCollection, ?callable $progress = null): ?array
    {
        $extracted = $this->archives->extractPath($path);
        if (! $extracted) {
            return null;
        }
        $added = ['photos' => 0, 'documents' => 0];
        foreach ($extracted as $i => $file) {
            $progress && $progress('Разбираем архив', $i, count($extracted));
            if ($file['photo'] && ! $this->classifier->looksLikeDocument(mb_strtolower($file['name']))) {
                $this->guard(function () use ($model, $photosCollection, $file, &$added) {
                    $added['photos'] += (int) $this->addPhoto($model, $photosCollection, $file['contents'], $file['name'], []);
                });
            } else {
                $added['documents'] += (int) $this->addDocumentFile($model, $documentsCollection, $file['name'], $file['contents'], null);
            }
        }

        return $added;
    }

    private function addPhoto(HasMedia $model, string $collection, string $contents, string $name, array $properties): bool
    {
        if ($model->hasFile(hash('sha256', $contents))) {
            return false;
        }
        $this->photos->fromString($model, $collection, $contents, $name, $properties);

        return true;
    }

    /** Вложения письма-кандидата и всех писем той же ветки; одинаковые файлы (по содержимому) — один раз. */
    public function attachmentsOf(?int $messageId, ?int $threadId): Collection
    {
        if (! $messageId && ! $threadId) {
            return collect();
        }

        // Письма старше «Файлы из писем с» у ящика в дело не едут: их файлы остаются в ящике.
        return Message::with(['attachments', 'account'])->where(fn ($q) => $q->when($messageId, fn ($q) => $q->whereKey($messageId))->when($threadId, fn ($q) => $q->orWhere('thread_id', $threadId)))
            ->get()->reject(fn (Message $m) => $m->filesFrozen())
            // Письмо остаётся при вложении: по нему видно, чей это кадр.
            ->flatMap(fn (Message $m) => $m->attachments->each(fn (Attachment $a) => $a->setRelation('message', $m)))
            ->unique(fn (Attachment $a) => $a->blob_sha ?? 'id:'.$a->id)->values();
    }

    private function addDocument(HasMedia $model, string $collection, Attachment $document): bool
    {
        // Пустая коллекция — документы этому получателю не нужны (кандидат: только кадры).
        if ($collection === '') {
            return false;
        }
        $contents = $document->contents();

        return $contents !== null && $this->addDocumentFile($model, $collection, $document->filename, $contents);
    }

    /** `source: null` — файл не из письма (архив, загруженный руками). */
    private function addDocumentFile(HasMedia $model, string $collection, string $name, string $contents, ?string $source = 'mail'): bool
    {
        if ($collection === '' || $model->hasFile($sha = hash('sha256', $contents))) {
            return false;
        }
        $this->guard(fn () => $model->addMediaFromString($contents)
            ->usingFileName(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $name) ?: 'dokument')
            ->usingName(pathinfo($name, PATHINFO_FILENAME))
            ->withCustomProperties(array_filter(['sha' => $sha, 'kind' => AttachmentClassifier::kindOf($name), 'source' => $source]))
            ->toMediaCollection($collection));

        return true;
    }

    private function guard(callable $action): void
    {
        try {
            $action();
        } catch (Throwable $e) {
            Log::warning('Вложение письма не перенеслось', ['error' => $e->getMessage()]);
        }
    }
}
