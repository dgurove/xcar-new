<?php

namespace App\Chats\Actions;

use App\Chats\AuthorKind;
use App\Chats\Chat;
use App\Chats\Events\ChatMessageChanged;
use App\Chats\Message;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Автор удаляет своё сообщение: текст и файлы остаются в базе (в CRM видно, что было), обеим
 * сторонам показывается «Сообщение удалено»; непрочитанное у другой стороны — на одно меньше.
 */
final class DeleteMessage
{
    public function __invoke(Message $message, User $by): Message
    {
        abort_unless($message->ownedBy($by), 404);
        DB::transaction(function () use ($message) {
            $chat = Chat::whereKey($message->chat_id)->lockForUpdate()->firstOrFail();
            $message->update(['deleted_at' => now()]);
            $other = $message->author_kind === AuthorKind::Participant ? 'staff' : 'user';
            if ($message->seq > $chat->{"read_seq_{$other}"} && $chat->{"unread_for_{$other}"} > 0) {
                $chat->decrement("unread_for_{$other}");
            }
        });
        ChatMessageChanged::dispatch($message->load('chat'));

        return $message;
    }
}
