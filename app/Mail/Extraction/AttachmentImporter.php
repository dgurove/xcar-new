<?php

namespace App\Mail\Extraction;

use App\Mail\Attachment;
use App\Mail\Message;
use App\Media\PhotoIngest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\HasMedia;
use Throwable;

/** Вложения письма → медиатека машины: документы как есть, фото через приём, архивы распаковываются, листы со снимками режутся. */
final class AttachmentImporter
{
    public function __construct(private AttachmentClassifier $classifier, private ArchivePhotoExtractor $archives, private PageScanCropper $cropper, private PhotoIngest $photos) {}

    public function import(HasMedia $model, Collection $attachments, string $photosCollection, string $documentsCollection, array $photoProperties = [], ?callable $progress = null): void
    {
        if ($attachments->isEmpty()) {
            return;
        }
        $classified = $this->classifier->classify($attachments);
        foreach ($classified['documents'] as $document) {
            $this->addDocument($model, $documentsCollection, $document);
        }
        $sources = [];
        foreach ($classified['photos'] as $photo) {
            if (($contents = $photo->contents()) !== null) {
                $sources[] = ['name' => $photo->filename, 'contents' => $contents];
            }
        }
        foreach ($classified['archives'] as $i => $archive) {
            $progress && $progress('Распаковываем архив', $i, count($classified['archives']));
            $extracted = $this->archives->extractPhotos($archive);
            if (! $extracted) {
                $this->addDocument($model, $documentsCollection, $archive);
                continue;
            }
            foreach ($extracted as $photo) {
                $sources[] = ['name' => $photo['name'], 'contents' => $photo['contents']];
            }
        }
        foreach ($sources as $i => $photo) {
            $progress && $progress('Разбираем фотографии', $i, count($sources));
            $this->guard(function () use ($model, $photosCollection, $photo, $photoProperties) {
                $cropped = $this->cropper->crop($photo['contents']);
                if (! $cropped) {
                    $this->photos->fromString($model, $photosCollection, $photo['contents'], $photo['name'], $photoProperties);

                    return;
                }
                $base = pathinfo($photo['name'], PATHINFO_FILENAME);
                foreach ($cropped as $n => $contents) {
                    $this->photos->fromString($model, $photosCollection, $contents, $base.(count($cropped) > 1 ? '-'.($n + 1) : '').'.jpg', $photoProperties);
                }
            });
        }
    }

    /** Вложения письма-кандидата и всех писем той же ветки; одинаковые файлы (по содержимому) — один раз. */
    public function attachmentsOf(?int $messageId, ?int $threadId): Collection
    {
        if (! $messageId && ! $threadId) {
            return collect();
        }

        return Message::with('attachments')->where(fn ($q) => $q->when($messageId, fn ($q) => $q->whereKey($messageId))->when($threadId, fn ($q) => $q->orWhere('thread_id', $threadId)))
            ->get()->flatMap(fn (Message $m) => $m->attachments)->unique(fn (Attachment $a) => $a->blob_sha ?? 'id:'.$a->id)->values();
    }

    private function addDocument(HasMedia $model, string $collection, Attachment $document): void
    {
        $this->guard(fn () => $model->addMediaFromString((string) $document->contents())
            ->usingFileName(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $document->filename) ?: 'dokument')
            ->usingName(pathinfo($document->filename, PATHINFO_FILENAME))
            ->toMediaCollection($collection));
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
