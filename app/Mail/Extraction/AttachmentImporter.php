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
            $extracted = $this->archives->extractPhotos($archive);
            if (! $extracted) {
                $added['documents'] += (int) $this->addDocument($model, $documentsCollection, $archive);

                continue;
            }
            // Кадры из архива и вырезки из листа — свойства того вложения, в котором они приехали.
            foreach ($extracted as $photo) {
                $sources[] = ['name' => $photo['name'], 'contents' => $photo['contents'], 'properties' => $propertiesOf($archive)];
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
        if ($contents === null || $model->hasFile($sha = hash('sha256', $contents))) {
            return false;
        }
        $this->guard(fn () => $model->addMediaFromString($contents)
            ->usingFileName(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $document->filename) ?: 'dokument')
            ->usingName(pathinfo($document->filename, PATHINFO_FILENAME))
            ->withCustomProperties(array_filter(['sha' => $sha, 'kind' => AttachmentClassifier::kindOf($document->filename), 'source' => 'mail']))
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
