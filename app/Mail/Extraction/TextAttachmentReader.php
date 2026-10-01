<?php

namespace App\Mail\Extraction;

use App\Mail\Attachment;
use App\Mail\Scope;

/**
 * Поля машины из вложения письма по его тексту (`DocumentFields`). Письма с предложениями (offer@): акт, договор
 * комиссии, оценка с торгов, исходное письмо .eml — текст тут же (`AttachmentText`, документы достаём и из ящика).
 * Парковка: только PDF и только уже прочитанное (`DocumentText::cached` — слой или OCR скана из очереди), чтобы
 * приём письма и `mail:read` не ждали OCR; непрочитанное дочитает `Jobs\ReadDocuments` и перечитает письмо.
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
        if ($attachment->message?->account?->scope === Scope::Park) {
            $text = DocumentText::wanted($attachment) ? DocumentText::cached($attachment) : null;

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
