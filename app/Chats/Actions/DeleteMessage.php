<?php

namespace App\Chats\Actions;

use App\Chats\AuthorKind;
use App\Chats\Chat;
use App\Chats\Events\ChatMessageChanged;
use App\Chats\Jobs\RetractChatMessage;
use App\Chats\Message;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Удалить сообщение: своё — автор; любое не служебное в чате площадки — админ, и менеджера тоже (17.11.2026, владелец,
 * `Message::deletableBy`). Текст и файлы остаются в базе: сотрудники видят удалённое серым с тем, кто удалил; собеседникам его
 * нет вовсе (`Message::hiddenFor`). Непрочитанное у другой стороны — на одно меньше; уведомления о сообщении — строка ленты и
 * сообщения бота в Telegram — отзываются (`RetractChatMessage`). Админ удалил — строкой в «Истории» машины.
 */
final class DeleteMessage
{
    public function __invoke(Message $message, User $by): Message
    {
        abort_unless($message->deletableBy($by), 404);
        DB::transaction(function () use ($message, $by) {
            $chat = Chat::whereKey($message->chat_id)->lockForUpdate()->firstOrFail();
            $message->update(['deleted_at' => now(), 'deleted_by' => $by->id]);
            $other = $message->author_kind === AuthorKind::Participant ? 'staff' : 'user';
            if ($message->seq > $chat->{"read_seq_{$other}"} && $chat->{"unread_for_{$other}"} > 0) {
                $chat->decrement("unread_for_{$other}");
            }
            if ($by->canEditDeals() && $chat->offer) {
                $chat->offer->log(OfferEventType::Note, $by, ['text' => 'Удалено сообщение в чате, собеседник '.$chat->displayName().': «'
                    .Str::limit((string) ($message->text ?: ($message->files()->exists() ? 'файл' : '')), 80).'»']);
            }
            RetractChatMessage::dispatch($message->id);
        });
        ChatMessageChanged::dispatch($message->load('chat'));

        return $message;
    }
}
