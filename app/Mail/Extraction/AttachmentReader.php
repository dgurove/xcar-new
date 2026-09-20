<?php

namespace App\Mail\Extraction;

use App\Mail\Attachment;

/**
 * Поля машины из вложения письма (скан заявки Альфы, акт, выписка ЭПТС). Читалки пока нет — `NullAttachmentReader`;
 * когда появится OCR или модель по API, её класс подключается в `AppServiceProvider` вместо пустой.
 */
interface AttachmentReader
{
    /** @return array<string, array{value: mixed, source: string}> те же поля, что у `ParkExtractor` (brand, model, vin, plate, year, color, value) */
    public function read(Attachment $attachment): array;
}
