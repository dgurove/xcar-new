<?php

namespace App\Chats\Actions;

use App\Chats\Events\ChatMessageChanged;
use App\Chats\Message;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/** Автор правит текст своего сообщения; пометка «изменено» остаётся навсегда. */
final class EditMessage
{
    public function __invoke(Message $message, User $by, ?string $text): Message
    {
        abort_unless($message->ownedBy($by), 404);
        $text = trim((string) $text);
        if ($text === '' && $message->files()->doesntExist()) {
            throw ValidationException::withMessages(['text' => 'Пустое сообщение']);
        }
        if ($text !== (string) $message->text) {
            $message->update(['text' => $text ?: null, 'edited_at' => now()]);
            ChatMessageChanged::dispatch($message->load('chat'));
        }

        return $message;
    }
}
