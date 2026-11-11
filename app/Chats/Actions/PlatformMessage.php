<?php

namespace App\Chats\Actions;

use App\Chats\AuthorKind;
use App\Chats\Chat;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Message;
use App\Workflow\Requirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Сообщение от «Администрации XCar» без автора-человека (06.11.2026, вопрос «Берём?» на согласии менеджера): как пузырь
 * сотрудника, у человека — непрочитанным и уведомлением чата (`Notify::chat`). Автоответ (`AutoReply`) — тоже без автора,
 * но стирается, когда сотрудник ответил; это сообщение остаётся. С просьбой шага (10.11.2026, `Chats\StepAsk`) — с её
 * кнопками под текстом; уведомления чата у такого нет: о ходе уже сказал `YourTurnNotice` (`Notify::chat`).
 */
final class PlatformMessage
{
    /**
     * @param  iterable<Media>  $media  документы сделки вложением (счёт страховой к «Пришёл счёт от страховой», 11.11.2026,
     *                                  владелец: «просим оплатить, а счёт, который надо оплатить, не отправляем — бред»)
     */
    public function __invoke(Chat $chat, string $text, ?Requirement $requirement = null, ?string $ask = null, iterable $media = []): Message
    {
        $message = DB::transaction(function () use ($chat, $text, $requirement, $ask, $media) {
            $chat = Chat::whereKey($chat->id)->lockForUpdate()->firstOrFail();
            $seq = $chat->messages_count + 1;
            $message = $chat->messages()->create(['seq' => $seq, 'author_kind' => AuthorKind::Staff, 'text' => $text, 'requirement_id' => $requirement?->id, 'ask' => $ask]);
            foreach ($media as $m) {
                if (! is_file($m->getPath())) {
                    continue;
                }
                $path = 'chats/'.$chat->id.'/'.Str::uuid().'.'.(pathinfo($m->file_name, PATHINFO_EXTENSION) ?: 'pdf');
                Storage::disk('private')->put($path, file_get_contents($m->getPath()));
                // Имя — как его видит менеджер («Счёт поставщика от 10.11.2026.pdf»), а не имя файла со сканера.
                $ext = pathinfo($m->file_name, PATHINFO_EXTENSION);
                $name = trim((string) $m->name) ?: $m->file_name;
                $name = $ext && ! str_ends_with(mb_strtolower($name), '.'.mb_strtolower($ext)) ? $name.'.'.$ext : $name;
                $message->files()->create(['name' => mb_substr($name, 0, 255), 'mime' => $m->mime_type ?: 'application/pdf', 'size' => $m->size, 'path' => $path]);
            }
            $chat->update(['messages_count' => $seq, 'last_message_at' => now(), 'unread_for_user' => $chat->unread_for_user + 1,
                'unread_for_staff' => 0, 'read_seq_staff' => $seq]);

            return $message;
        });
        ChatMessagePosted::dispatch($message->load('chat.offer', 'chat.user', 'chat.manager', 'files'));

        return $message;
    }
}
