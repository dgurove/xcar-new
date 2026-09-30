<?php

namespace App\Telegram;

use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Бот xcar: владельцу и админам — сообщения с кнопками решения, менеджерам — уведомления по сделкам,
 * привязка чата и вход (`/start`, StartLink). Сообщения с кнопками и ответы на нажатия. Без SDK — четыре
 * метода Bot API через Http. До api.telegram.org с сервера доходит только
 * IPv6 — клиент это и просит. `send` бросает исключение (его зовёт джоба,
 * повтор — дело очереди), ответы на нажатия ошибку гасят: их зовёт опрос.
 */
class Bot
{
    public function configured(): bool
    {
        return $this->token() !== '';
    }

    public function ownerChatId(): ?int
    {
        $id = trim((string) config('xcar.telegram.owner_chat_id'));

        return $id === '' ? null : (int) $id;
    }

    /**
     * Куда слать сообщения владельца и чьи нажатия под ними принимать: чат из настроек и чаты привязанных админов.
     *
     * @return list<int>
     */
    public function ownerChats(): array
    {
        $admins = User::where('role', Role::Admin)->whereNotNull('telegram_chat_id')->pluck('telegram_chat_id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique(array_filter([$this->ownerChatId(), ...$admins])));
    }

    /** Имя бота для ссылок t.me: из настроек, иначе у самого Telegram (getMe) — один раз, дальше из кэша. */
    public function username(): ?string
    {
        $name = trim((string) config('xcar.telegram.username'), " @\t");
        if ($name !== '' || ! $this->configured()) {
            return $name ?: null;
        }
        if ($name = Cache::get('telegram:username')) {
            return $name;
        }
        // Неудачу помним десять минут: страница не должна ждать Telegram на каждом открытии.
        if (Cache::has('telegram:username:failed')) {
            return null;
        }
        try {
            $name = (string) $this->client(5)->get('getMe')->throw()->json('result.username');
        } catch (Throwable $e) {
            Log::warning('Telegram: getMe не ответил', ['error' => $e->getMessage()]);
        }
        if (! $name) {
            Cache::put('telegram:username:failed', true, 600);

            return null;
        }
        Cache::forever('telegram:username', $name);

        return $name;
    }

    /** Ссылка на бота с параметром /start. */
    public function startUrl(string $payload): ?string
    {
        $name = $this->username();

        return $name ? 'https://t.me/'.$name.'?start='.$payload : null;
    }

    /**
     * `silent` — без звука (тихие часы).
     *
     * @param  array<int, array<int, array{text: string, callback_data?: string, url?: string}>>|null  $keyboard
     */
    public function send(int $chatId, string $text, ?array $keyboard = null, bool $silent = false): void
    {
        $this->call('sendMessage', $this->payload($chatId, $text, $keyboard) + ($silent ? ['disable_notification' => 'true'] : []))->throw();
    }

    /** Человек закрыл нам чат: заблокировал бота или удалил переписку — слать туда больше нечего. */
    public static function chatGone(Throwable $e): bool
    {
        $status = $e instanceof RequestException ? $e->response->status() : 0;

        return $status === 403 || ($status === 400 && str_contains($e->getMessage(), 'chat not found'));
    }

    public function edit(int $chatId, int $messageId, string $text, ?array $keyboard = null): void
    {
        try {
            $this->call('editMessageText', $this->payload($chatId, $text, $keyboard) + ['message_id' => $messageId])->throw();
        } catch (Throwable $e) {
            // Второе нажатие той же кнопки в ту же минуту — текст тот же, это не сбой.
            if (! str_contains($e->getMessage(), 'message is not modified')) {
                Log::warning('Telegram: сообщение не переписалось', ['chat' => $chatId, 'message' => $messageId, 'error' => $e->getMessage()]);
            }
        }
    }

    /** Всплывашка на нажатие; Telegram принимает не больше 200 знаков. */
    public function answer(string $queryId, string $text): void
    {
        try {
            $this->call('answerCallbackQuery', ['callback_query_id' => $queryId, 'text' => mb_strimwidth($text, 0, 200, '…')])->throw();
        } catch (Throwable $e) {
            Log::warning('Telegram: не ответил на нажатие', ['query' => $queryId, 'error' => $e->getMessage()]);
        }
    }

    /** Длинный опрос: Telegram держит запрос до `$timeout` секунд. @return list<array<string, mixed>> */
    public function updates(int $offset, int $timeout): array
    {
        return $this->client($timeout + 10)->get('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => json_encode(['message', 'callback_query', 'my_chat_member'])])->throw()->json('result', []);
    }

    /** Перед опросом: при живом вебхуке getUpdates отвечает 409. */
    public function dropWebhook(): void
    {
        $this->call('deleteWebhook', [])->throw();
    }

    private function payload(int $chatId, string $text, ?array $keyboard): array
    {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'link_preview_options' => json_encode(['is_disabled' => true])];
        if ($keyboard !== null) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
        }

        return $payload;
    }

    /** Путь до Telegram по IPv6 моргает: три попытки с паузой. Ответ 4xx — не сбой сети, его не повторяем. */
    private function call(string $method, array $payload)
    {
        return $this->client(20)->retry(3, 1500, fn (Throwable $e) => ! ($e instanceof RequestException && $e->response->clientError()))->asForm()->post($method, $payload);
    }

    private function client(int $timeout): PendingRequest
    {
        return Http::baseUrl('https://api.telegram.org/bot'.$this->token().'/')->timeout($timeout)->withOptions(['force_ip_resolve' => 'v6']);
    }

    private function token(): string
    {
        return trim((string) config('xcar.telegram.token'));
    }
}
