<?php

namespace App\Mail\Extraction;

use App\Mail\Attachment;

/** Вложения не читаются: заготовка под OCR или разбор моделью. */
final class NullAttachmentReader implements AttachmentReader
{
    public function read(Attachment $attachment): array
    {
        return [];
    }
}
