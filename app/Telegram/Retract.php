<?php

namespace App\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Стереть сообщения бота о предмете (`telegram_messages.subject`: `chat-message:12`, `candidate:5`…) — одна дверь отзыва (25.11.2026,
 * владелец: «удалили в чате — и в Telegram надёжно, если меньше 48 часов»). Удалилось — пометка (её ставит и журнал на
 * `deleteMessage`). Нельзя навсегда — сообщения уже нет, старше 48 часов («message can't be deleted»), бот остановлен, чат
 * удалён — пометка и строка в лог. Сбой временный — сеть, 429, 5xx: без пометки, исключение наружу — задание повторится
 * (`tries` / `backoff` у заданий отзыва), сообщение не останется у человека из-за минутной ошибки Telegram.
 */
final class Retract
{
    public function __construct(private Bot $bot) {}

    /** @param  string|list<string>  $subjects */
    public function subject(string|array $subjects, string $what = 'сообщение'): void
    {
        $failed = null;
        foreach (ChatMessage::whereIn('subject', (array) $subjects)->where('direction', ChatMessage::OUT)->whereNull('deleted_at')
            ->whereNotNull('message_id')->get() as $message) {
            try {
                $this->bot->delete((int) $message->chat_id, (int) $message->message_id);
                $message->deleted_at || $message->update(['deleted_at' => now()]);
            } catch (Throwable $e) {
                if (self::transient($e)) {
                    $failed = $e;

                    continue;
                }
                Log::info('Telegram: '.$what.' не удалилось', ['message' => $message->id, 'error' => $e->getMessage()]);
                $message->update(['deleted_at' => now()]);
            }
        }
        if ($failed) {
            throw new RuntimeException('Telegram: '.$what.' не удалилось, повторим: '.$failed->getMessage(), 0, $failed);
        }
    }

    /** Удаление можно повторить: обрыв (повтор удаления безвреден), 429, 5xx. Ответ 4xx — навсегда. */
    public static function transient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError());
    }
}
