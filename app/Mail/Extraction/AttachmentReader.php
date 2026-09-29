<?php

namespace App\Mail\Extraction;

use App\Mail\Attachment;

/**
 * Поля машины из вложения письма (акт, договор, оценка, скан заявки Альфы). Сейчас — `TextAttachmentReader` по тексту
 * документа; OCR или модель по API для сканов подключается в `AppServiceProvider` вместо него.
 */
interface AttachmentReader
{
    /** @return array<string, array{value: mixed, source: string}> те же поля, что у `ParkExtractor` (brand, model, vin, plate, year, color, value) */
    public function read(Attachment $attachment): array;
}
