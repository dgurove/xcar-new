<?php

namespace App\Telegram\Offers;

use App\Chats\AuthorKind;
use App\Chats\Message;
use App\Notifications\ChatNotice;
use App\Users\User;

/**
 * Уведомление чата — через бот предложений, если переписку менеджер начал там (вопрос 💬, последнее его сообщение
 * пришло из бота, `chat_messages.via_bot`): админу вопрос, на который отвечают реплаем, менеджеру ответ туда, где
 * спрашивал. Иначе — основной бот, как раньше (`ChatNotice::via`).
 */
final class OffersBotChannel
{
    public function __construct(private Questions $questions) {}

    public static function routes(Message $message, User $user): bool
    {
        if (! app(OffersBot::class)->configured()) {
            return false;
        }
        $viaBot = $message->author_kind === AuthorKind::Participant ? $message->via_bot : self::botThread($message->chat_id);
        $s = $user->notification_settings ?? [];
        if (! $viaBot || ($s['telegram'] ?? true) === false || in_array('chats', $s['telegram_off'] ?? [], true)) {
            return false;
        }

        return Subscriber::where('user_id', $user->id)->whereNull('blocked_at')->exists();
    }

    /** Последнее сообщение участника пришло из бота — ответы по чату идут туда. */
    public static function botThread(int $chatId): bool
    {
        return (bool) Message::where('chat_id', $chatId)->where('author_kind', AuthorKind::Participant)->orderByDesc('seq')->value('via_bot');
    }

    public function send(User $user, ChatNotice $notice): void
    {
        $message = $notice->message();
        // Автоответ площадки (без автора) в Telegram не идёт — как и у основного бота.
        if ($message->author_kind !== AuthorKind::Participant && ! $message->author_id) {
            return;
        }
        if ($sub = Subscriber::with('user')->where('user_id', $user->id)->whereNull('blocked_at')->first()) {
            $this->questions->deliver($sub, $message, $notice->forStaff());
        }
    }
}
