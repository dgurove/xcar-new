<?php

namespace App\Chats\Actions;

use App\Chats\AuthorKind;
use App\Chats\Chat;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Message;
use App\Users\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Сообщение в чат: номер под блокировкой строки чата, файлы на закрытом диске под своим именем. */
final class PostMessage
{
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'application/pdf'];

    /** @param  list<UploadedFile>  $files */
    public function __invoke(Chat $chat, User $by, ?string $text, array $files = []): Message
    {
        $text = trim((string) $text);
        if ($text === '' && ! $files) {
            throw ValidationException::withMessages(['text' => 'Пустое сообщение']);
        }
        foreach ($files as $file) {
            if (! in_array($file->getMimeType(), self::MIMES, true)) {
                throw ValidationException::withMessages(['files' => 'Только фото и PDF']);
            }
        }

        $message = DB::transaction(function () use ($chat, $by, $text, $files) {
            $chat = Chat::whereKey($chat->id)->lockForUpdate()->firstOrFail();
            $kind = $by->isStaff() ? AuthorKind::Staff : AuthorKind::Participant;
            $message = $chat->messages()->create(['seq' => $chat->messages_count + 1, 'author_id' => $by->id, 'author_kind' => $kind, 'text' => $text ?: null]);
            foreach ($files as $file) {
                $path = 'chats/'.$chat->id.'/'.Str::uuid().'.'.($file->guessExtension() ?: 'bin');
                Storage::disk('private')->put($path, $file->getContent());
                $message->files()->create(['name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime' => (string) $file->getMimeType(), 'size' => $file->getSize(), 'path' => $path]);
            }
            $chat->update([
                'messages_count' => $chat->messages_count + 1,
                'last_message_at' => now(),
                'unread_for_user' => $kind === AuthorKind::Participant ? 0 : $chat->unread_for_user + 1,
                'unread_for_staff' => $kind === AuthorKind::Participant ? $chat->unread_for_staff + 1 : 0,
            ]);

            return $message;
        });
        // После коммита: иначе браузер придёт за «всё после N» раньше, чем сообщение видно.
        ChatMessagePosted::dispatch($message->load('chat.offer', 'chat.user', 'files'));

        return $message;
    }
}
