<?php

namespace App\Mail\Scan;

use App\Mail\Attachment;
use App\Mail\Extraction\DocumentText;
use App\Mail\Message;
use App\Support\Docs;
use Illuminate\Support\Collection;

/** Файлы писем для «✨»: что умеет прочитать, без повторов (скан в «ч.1» и в пересылке — один), документы первыми. */
final class Files
{
    /**
     * Подпись файла для людей: «фото», «заявка», «эптс»; имя сканера из одних цифр («20260930142927…») — «скан»;
     * длинное имя («040 ВЫПИСКА ИЗ ЭЛЕКТРОННОГО ПАСПОРТА …») обрезается — оно уходит и в историю дела.
     */
    public static function label(Attachment $attachment, ?bool $photo = null): string
    {
        if ($photo ?? $attachment->isPhoto()) {
            return 'фото';
        }
        $label = Docs::label((string) $attachment->filename);

        return preg_match('/^[\d\s_\-\[\]()]+$|^\[?untitled\]?$/iu', $label) ? 'скан' : mb_strimwidth($label, 0, 40, '…');
    }

    /** @param iterable<Message> $messages @return Collection<int, Attachment> */
    public static function of(iterable $messages): Collection
    {
        return collect($messages)->flatMap(fn (Message $m) => $m->files())
            ->filter(fn (Attachment $a) => DocumentText::scannable($a))
            ->unique(fn (Attachment $a) => $a->blob_sha ?: $a->fileKey())
            ->sortBy(fn (Attachment $a) => $a->isPhoto() ? 1 : 0)
            ->values();
    }
}
