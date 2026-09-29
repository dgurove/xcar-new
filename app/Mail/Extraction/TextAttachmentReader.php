<?php

namespace App\Mail\Extraction;

use App\Mail\Attachment;
use App\Mail\Scope;

/**
 * Поля машины из вложения письма по его тексту (`AttachmentText` → `DocumentFields`): акт, договор комиссии,
 * оценка с торгов, исходное письмо .eml. Без OCR — скан-картинка ничего не даёт. Только письма с предложениями
 * (offer@): их несколько в день, документы достаём и из ящика. У парковки тысячи сканов заявок без текстового
 * слоя — им нужен OCR, а `pdftotext` на каждом при перечитке ящика только грузил бы сервер.
 */
final class TextAttachmentReader implements AttachmentReader
{
    private const TYPES = ['pdf', 'docx', 'xlsx', 'xlsm', 'csv', 'txt', 'eml'];

    public function read(Attachment $attachment): array
    {
        $ext = strtolower(pathinfo((string) $attachment->filename, PATHINFO_EXTENSION));
        if ($attachment->is_inline || (! in_array($ext, self::TYPES, true) && ! in_array($attachment->mime, ['application/pdf', 'message/rfc822'], true))) {
            return [];
        }
        $attachment->loadMissing('message.account');
        if ($attachment->message?->account?->scope !== Scope::Offers) {
            return [];
        }
        $path = $attachment->file();
        $text = $path ? AttachmentText::of($path, (string) $attachment->filename, $attachment->mime) : null;

        return $text ? DocumentFields::extract($text) : [];
    }
}
