<?php

namespace App\Mail\Extraction;

use App\Mail\Attachment;

/** Вложения письма по корзинам: фото, документы, архивы. Потерять файл хуже, чем положить не туда. */
final class AttachmentClassifier
{
    private const DOCUMENT_MARKERS = ['стс', 'птс', 'свидетельство', 'полис', 'акт', 'договор', 'соглашение', 'счёт', 'счет', 'квитанция', 'осмотр документов', 'оценка', 'паспорт', 'страховое', 'удостоверение', 'выписка', 'осаго', 'каско', 'эптс'];

    /** @return array{photos: list<Attachment>, documents: list<Attachment>, archives: list<Attachment>} */
    public function classify(iterable $attachments): array
    {
        $photos = $documents = $archives = [];
        foreach ($attachments as $attachment) {
            if ($attachment->is_inline) {
                continue;
            }
            $mime = mb_strtolower((string) $attachment->mime);
            $name = mb_strtolower((string) $attachment->filename);
            if (str_starts_with($mime, 'image/')) {
                $this->looksLikeDocument($name) ? $documents[] = $attachment : $photos[] = $attachment;
            } elseif (ArchivePhotoExtractor::isArchive($mime) || ArchivePhotoExtractor::isArchiveName($name)) {
                $archives[] = $attachment;
            } elseif ($mime === 'message/rfc822') {
                continue;
            } else {
                $documents[] = $attachment;
            }
        }

        return ['photos' => $photos, 'documents' => $documents, 'archives' => $archives];
    }

    private function looksLikeDocument(string $filename): bool
    {
        foreach (self::DOCUMENT_MARKERS as $marker) {
            if (str_contains($filename, $marker)) {
                return true;
            }
        }

        return false;
    }
}
