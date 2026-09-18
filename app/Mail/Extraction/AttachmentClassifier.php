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

    /** Что за документ по имени файла: договор, акт, оценка, СТС, ПТС, соглашение, осмотр. Не распознан — null. */
    public static function kindOf(?string $filename): ?string
    {
        $name = mb_strtolower(str_replace('ё', 'е', (string) $filename));
        foreach ([
            'contract' => ['договор'],
            'act' => ['акт прием', 'акт приём', 'акт п/п', 'акт_прием', 'акт_приём', 'акт-прием', 'приема-передачи', 'приёма-передачи', 'приема_передачи'],
            'inspection' => ['акт осмотра', 'акт_осмотра', 'осмотр'],
            'valuation' => ['оценк', 'оценко', 'протокол', 'аукцион', 'торг'],
            'agreement' => ['соглашени'],
            'sts' => ['стс', 'свидетельств'],
            'pts' => ['птс', 'эптс', 'паспорт тс', 'электронного паспорта', 'elektronnogo pasporta'],
        ] as $kind => $markers) {
            foreach ($markers as $marker) {
                if (str_contains($name, $marker)) {
                    return $kind;
                }
            }
        }

        return null;
    }

    public static function kindLabel(?string $kind): ?string
    {
        return match ($kind) {
            'contract' => 'Договор', 'act' => 'Акт п/п', 'inspection' => 'Акт осмотра', 'valuation' => 'Оценка',
            'agreement' => 'Соглашение', 'sts' => 'СТС', 'pts' => 'ПТС', default => null,
        };
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
