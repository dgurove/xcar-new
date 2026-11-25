<?php

namespace App\Chats\Jobs;

use App\Notifications\ChatNotice;
use App\Telegram\Retract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Удалённое сообщение чата — бесследно (17.11.2026, владелец): сообщения бота о нём в Telegram у всех получателей (`ChatNotice`
 * пишет им `subject` `chat-message:{id}`, вместе с файлами вслед) и строки ленты. Telegram даёт боту удалить своё сообщение
 * 48 часов — старше остаётся, в журнал. Ушедшие до 17.11.2026 предмета не имеют и не отзываются. Пуш не отозвать.
 * Надёжно (25.11.2026, владелец): сбой Telegram (сеть, 429, 5xx) — повтор заданием (`Telegram\Retract`), а не пометка «удалено»;
 * второй проход через 20 минут (`sweep`) ловит сообщение бота, что ушло позже — повтором доставки (`RetryDelivery`) или в ту же секунду.
 */
final class RetractChatMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600, 7200];
    }

    public function __construct(public int $messageId, public bool $sweep = false)
    {
        $this->afterCommit();
    }

    public static function subjectOf(int $messageId): string
    {
        return 'chat-message:'.$messageId;
    }

    public function handle(Retract $retract): void
    {
        // Ленту — сразу, Telegram — может бросить на повтор.
        DatabaseNotification::where('type', ChatNotice::class)->where('data->chat_message', $this->messageId)->delete();
        if (! $this->sweep && $this->attempts() <= 1) {
            self::dispatch($this->messageId, sweep: true)->delay(now()->addMinutes(20));
        }
        $retract->subject(self::subjectOf($this->messageId), 'сообщение чата');
    }
}
