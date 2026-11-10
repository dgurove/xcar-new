<?php

namespace App\Chats\Actions;

use App\Chats\AuthorKind;
use App\Chats\Chat;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Message;
use App\Workflow\Requirement;
use Illuminate\Support\Facades\DB;

/**
 * Сообщение от «Администрации XCar» без автора-человека (06.11.2026, вопрос «Берём?» на согласии менеджера): как пузырь
 * сотрудника, у человека — непрочитанным и уведомлением чата (`Notify::chat`). Автоответ (`AutoReply`) — тоже без автора,
 * но стирается, когда сотрудник ответил; это сообщение остаётся. С просьбой шага (10.11.2026, `Chats\StepAsk`) — с её
 * кнопками под текстом; уведомления чата у такого нет: о ходе уже сказал `YourTurnNotice` (`Notify::chat`).
 */
final class PlatformMessage
{
    public function __invoke(Chat $chat, string $text, ?Requirement $requirement = null): Message
    {
        $message = DB::transaction(function () use ($chat, $text, $requirement) {
            $chat = Chat::whereKey($chat->id)->lockForUpdate()->firstOrFail();
            $seq = $chat->messages_count + 1;
            $message = $chat->messages()->create(['seq' => $seq, 'author_kind' => AuthorKind::Staff, 'text' => $text, 'requirement_id' => $requirement?->id]);
            $chat->update(['messages_count' => $seq, 'last_message_at' => now(), 'unread_for_user' => $chat->unread_for_user + 1,
                'unread_for_staff' => 0, 'read_seq_staff' => $seq]);

            return $message;
        });
        ChatMessagePosted::dispatch($message->load('chat.offer', 'chat.user', 'chat.manager', 'files'));

        return $message;
    }
}
