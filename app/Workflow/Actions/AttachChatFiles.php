<?php

namespace App\Workflow\Actions;

use App\Chats\Message;
use App\Workflow\Requirement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Файлы сообщения чата — в просьбу шага (10.11.2026, владелец: менеджеры шлют чек фото в чат, а не на сайт): «Это чек об
 * оплате» под его сообщением и «Приложить за менеджера» у сотрудника. Копия — в чате файл остаётся; дальше как приложенный
 * на сайте (`AttachRequirementFiles`: снимок через `PhotoIngest`, PDF как есть), PDF для страховой склеит `PaymentProofPdf`.
 */
final class AttachChatFiles
{
    public function __construct(private AttachRequirementFiles $attach) {}

    /** Сколько файлов приложено. */
    public function __invoke(Requirement $requirement, Message $message): int
    {
        $disk = Storage::disk('private');
        $n = 0;
        foreach ($message->files as $file) {
            if (! $disk->exists($file->path)) {
                continue;
            }
            // Медиатека переносит загруженный файл — отдаём ей копию, чтобы файл чата остался на месте.
            $tmp = tempnam(sys_get_temp_dir(), 'chat-file-');
            copy($disk->path($file->path), $tmp);
            try {
                ($this->attach)($requirement, new UploadedFile($tmp, $file->name, $file->mime, null, true));
                $n++;
            } finally {
                is_file($tmp) && @unlink($tmp);
            }
        }

        return $n;
    }
}
