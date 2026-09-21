<?php

namespace App\Mail\Extraction;

use App\Mail\Candidate;
use App\Mail\Message;
use App\Media\PhotoIngest;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Кадр карточки кандидата «Из писем»: один, маленький (480 px, webp), на быстром диске `hot`.
 * Берётся первое фото письма (архив распаковывается, лист со снимками режется), остальные кадры
 * кандидату не нужны — при «Завести» их принесёт к ТС импорт ветки из закреплённых вложений.
 */
final class CandidateCard
{
    public const MAX_DIMENSION = 480;

    public function __construct(private AttachmentClassifier $classifier, private ArchivePhotoExtractor $archives, private PageScanCropper $cropper, private PhotoIngest $photos) {}

    /** Кадр уже есть — не трогаем; в письме фото нет — null. */
    public function make(Candidate $candidate, Message $message): ?Media
    {
        if ($card = $candidate->card()) {
            return $card;
        }
        if ($message->filesFrozen()) {
            return null; // история ящика: кадр не делаем, фото остаются в письме
        }
        $message->loadMissing('attachments');
        $classified = $this->classifier->classify($message->attachments);
        $source = null;
        foreach ($classified['photos'] as $photo) {
            if (($contents = $photo->contents()) !== null) {
                $source = ['name' => $photo->filename, 'contents' => $contents];
                break;
            }
        }
        if (! $source) {
            foreach ($classified['archives'] as $archive) {
                if ($extracted = $this->archives->extractPhotos($archive, 1)) {
                    $source = ['name' => $extracted[0]['name'], 'contents' => $extracted[0]['contents']];
                    break;
                }
            }
        }
        if (! $source) {
            return null;
        }
        try {
            $contents = $this->cropper->crop($source['contents'])[0] ?? $source['contents'];

            return $this->photos->fromString($candidate, 'card', $contents, $source['name'], [], self::MAX_DIMENSION);
        } catch (Throwable $e) {
            Log::warning('Кадр кандидата не сделался', ['candidate' => $candidate->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
