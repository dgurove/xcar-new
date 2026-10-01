<?php

namespace App\Mail\Extraction;

use App\Mail\Attachment;
use App\Mail\Scope;

/**
 * Поля машины из вложения письма по его тексту (`DocumentFields`). Письма с предложениями (offer@): акт, договор
 * комиссии, оценка с торгов, исходное письмо .eml — текст тут же (`AttachmentText`, документы достаём и из ящика).
 * Парковка: текстовый слой PDF и документов сразу, сканы и фото — только прочитанные «✨ Распознать»
 * (`DocumentText::layer`: кеш или слой) — приём письма и `mail:read` OCR не ждут и сами его не запускают.
 */
final class TextAttachmentReader implements AttachmentReader
{
    private const TYPES = ['pdf', 'docx', 'xlsx', 'xlsm', 'csv', 'txt', 'eml'];

    public function read(Attachment $attachment): array
    {
        $attachment->loadMissing('message.account');
        if ($attachment->message?->account?->scope === Scope::Park && $attachment->isImage()) {
            $text = DocumentText::cached($attachment);

            return $text ? DocumentFields::extract($text) : [];
        }
        $ext = strtolower(pathinfo((string) $attachment->filename, PATHINFO_EXTENSION));
        if ($attachment->is_inline || (! in_array($ext, self::TYPES, true) && ! in_array($attachment->mime, ['application/pdf', 'message/rfc822'], true))) {
            return [];
        }
        if ($attachment->message?->account?->scope === Scope::Park) {
            $text = DocumentText::layer($attachment);

            return $text ? DocumentFields::extract($text) : [];
        }
        if ($attachment->message?->account?->scope !== Scope::Offers) {
            return [];
        }
        $path = $attachment->file();
        $text = $path ? AttachmentText::of($path, (string) $attachment->filename, $attachment->mime) : null;

        return $text ? DocumentFields::extract($text) : [];
    }
}
