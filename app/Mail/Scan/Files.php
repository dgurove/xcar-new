<?php

namespace App\Mail\Scan;

use App\Mail\Attachment;
use App\Mail\Extraction\DocumentText;
use App\Mail\Message;
use Illuminate\Support\Collection;

/** Файлы писем для «✨»: что умеет прочитать, без повторов (скан в «ч.1» и в пересылке — один), документы первыми. */
final class Files
{
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
