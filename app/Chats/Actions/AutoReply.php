<?php

namespace App\Chats\Actions;

use App\Chats\AuthorKind;
use App\Chats\Chat;
use App\Chats\Events\ChatMessageChanged;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Hours;
use App\Chats\Message;
use Illuminate\Support\Facades\DB;

/**
 * Автоответ администрации: человек написал площадке (чат без менеджера) — через несколько секунд
 * «печатает» и пузырь от «Администрации XCar»: получили, когда ждать, включите уведомления.
 * Один раз на чат (06.10.2026, владелец: повтор через день раздражает): чат — машина и человек, в чате
 * по другой машине — снова один раз. Автоответ — сообщение Staff без автора; таких больше нет.
 * Сотрудник ответил — автоответ стирается (`retract`, владелец 10.10.2026): он был только чтобы человек не ждал молча.
 */
final class AutoReply
{
    /**
     * Нужен ли автоответ на это сообщение: чат площадки, написал участник, ни автоответа, ни сотрудника в этом чате ещё
     * не было (стёртый автоответ остаётся ответом сотрудника — второй раз не приходит).
     */
    public static function due(Message $message): bool
    {
        $chat = $message->chat;
        if ($chat->manager_id || $message->author_kind !== AuthorKind::Participant) {
            return false;
        }

        return ! $chat->messages()->where('author_kind', AuthorKind::Staff)->exists();
    }

    /** Сотрудник ответил в чате площадки — автоответ из ленты убирается у обеих сторон, непрочитанным он больше не висит. */
    public function retract(Chat $chat): void
    {
        $gone = DB::transaction(function () use ($chat) {
            $chat = Chat::whereKey($chat->id)->lockForUpdate()->firstOrFail();
            $auto = $chat->messages()->where('author_kind', AuthorKind::Staff)->whereNull('author_id')->get();
            foreach ($auto as $message) {
                if ($message->seq > $chat->read_seq_user && $chat->unread_for_user > 0) {
                    $chat->decrement('unread_for_user');
                }
                $message->delete();
            }

            return $auto->each->setRelation('chat', $chat);
        });
        $gone->each(fn (Message $m) => ChatMessageChanged::dispatch($m));
    }

    public static function text(Chat $chat, ?\DateTimeInterface $at = null): string
    {
        $open = Hours::open($at);
        $lines = [
            $open ? 'Добрый день! Получили Ваше сообщение' : 'Здравствуйте! Получили Ваше сообщение',
            $open ? 'Как правило, сотрудники отвечают в течение часа.' : 'Сейчас нерабочее время, сотрудники ответят с '.Hours::OPEN.':00.',
        ];
        // Гостю с /contacts уведомления включить негде — ответ появится там же.
        $lines[1] .= $chat->user_id ? ' Чтобы не пропустить ответ, рекомендуем включить уведомления [в настройках](/account/notifications/settings)' : ' Ответ появится на этой странице';

        return implode("\n", $lines);
    }

    public function __invoke(Chat $chat): ?Message
    {
        $message = DB::transaction(function () use ($chat) {
            $chat = Chat::whereKey($chat->id)->lockForUpdate()->firstOrFail();
            $seq = $chat->messages_count + 1;
            $message = $chat->messages()->create(['seq' => $seq, 'author_kind' => AuthorKind::Staff, 'text' => self::text($chat)]);
            $chat->update(['messages_count' => $seq, 'last_message_at' => now(), 'unread_for_user' => $chat->unread_for_user + 1, 'read_seq_staff' => $seq]);

            return $message;
        });
        ChatMessagePosted::dispatch($message->load('chat.offer', 'chat.user', 'chat.manager', 'files'));

        return $message;
    }
}
