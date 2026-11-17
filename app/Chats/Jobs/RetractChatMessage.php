<?php

namespace App\Chats\Jobs;

use App\Notifications\ChatNotice;
use App\Telegram\Bot;
use App\Telegram\ChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Удалённое сообщение чата — бесследно (17.11.2026, владелец): сообщения бота о нём в Telegram у всех получателей (`ChatNotice`
 * пишет им `subject` `chat-message:{id}`, вместе с файлами вслед) и строки ленты. Telegram даёт боту удалить своё сообщение
 * 48 часов — старше остаётся, в журнал. Ушедшие до 17.11.2026 предмета не имеют и не отзываются. Пуш не отозвать.
 */
final class RetractChatMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $messageId)
    {
        $this->afterCommit();
    }

    public static function subjectOf(int $messageId): string
    {
        return 'chat-message:'.$messageId;
    }

    public function handle(Bot $bot): void
    {
        $sent = ChatMessage::where('subject', self::subjectOf($this->messageId))->where('direction', ChatMessage::OUT)->whereNull('deleted_at')->get();
        foreach ($sent as $message) {
            try {
                $bot->delete((int) $message->chat_id, (int) $message->message_id);
                $message->update(['deleted_at' => now()]);
            } catch (Throwable $e) {
                // Старше 48 часов, человек удалил сам или остановил бота — сообщения не удалить, и ладно.
                Log::info('Telegram: сообщение чата не отозвалось', ['message' => $message->id, 'error' => $e->getMessage()]);
                $message->update(['deleted_at' => now()]);
            }
        }
        DatabaseNotification::where('type', ChatNotice::class)->where('data->chat_message', $this->messageId)->delete();
    }
}
