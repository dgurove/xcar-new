<?php

namespace App\Telegram\Actions;

use App\Telegram\Bot;
use App\Telegram\Chat;
use App\Telegram\ChatMessage;
use App\Telegram\Journal;
use App\Users\User;
use Illuminate\Http\UploadedFile;

/**
 * Сотрудник пишет из «Бот Telegram» от имени бота: текст — одним сообщением, файлы — следом, каждый своим
 * (картинка фото, остальное документом). Ответ — на сообщение из переписки. Бот при этом живёт как жил:
 * его автоответы и логика человека в чате не учитывают. Отказ Telegram (бот заблокирован) остаётся
 * в переписке пузырём с причиной и уходит наружу исключением.
 */
final class SendAsBot
{
    public function __construct(private Bot $bot, private Journal $journal) {}

    /** @param list<UploadedFile> $files */
    public function __invoke(Chat $chat, User $author, ?string $text, array $files = [], ?int $replyTo = null): void
    {
        $reply = $replyTo ? ChatMessage::where('chat_id', $chat->id)->whereKey($replyTo)->value('message_id') : null;
        $this->journal->by($author, function () use ($chat, $text, $files, $reply) {
            if (($text = trim((string) $text)) !== '') {
                $this->bot->send($chat->id, e($text), null, false, $reply);
                $reply = null;
            }
            foreach ($files as $file) {
                $this->bot->sendFile($chat->id, $file, null, $reply);
                $reply = null;
            }
        });
    }

    public function edit(ChatMessage $message, string $text): bool
    {
        return $this->bot->edit($message->chat_id, (int) $message->message_id, e(trim($text)), $message->keyboard);
    }

    public function delete(ChatMessage $message): void
    {
        $this->bot->delete($message->chat_id, (int) $message->message_id);
    }
}
