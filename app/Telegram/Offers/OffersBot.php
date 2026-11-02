<?php

namespace App\Telegram\Offers;

use App\Telegram\Bot;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Второй бот — @xcar_offers_bot: каждое новое предложение менеджеру одним сообщением (`Card`, 03.11.2026). Тот же клиент
 * Bot API, что у основного (через Цюрих, повторы, 429), но свой токен и кэш, без журнала CRM: `telegram_chats` рассчитан
 * на одного бота. Сообщения — HTML. Возвращает id сообщения в Telegram.
 */
final class OffersBot extends Bot
{
    public const NAME = 'offers';

    public function __construct()
    {
        parent::__construct(null);
    }

    protected function config(string $key): mixed
    {
        return config('xcar.telegram.offers.'.$key);
    }

    protected function cacheKey(string $key): string
    {
        return 'offers-bot:'.$key;
    }

    /** У этого бота владельца нет: сообщения владельцу и админам шлёт основной. */
    public function ownerChatId(): ?int
    {
        return null;
    }

    /** Кнопка «Открыть в XCar» — входом через Telegram, когда домен выставлен в BotFather. */
    public function logsIn(): bool
    {
        return (bool) $this->config('login');
    }

    public function lanes(): int
    {
        return max(1, (int) $this->config('lanes'));
    }

    /** @param  array<string, mixed>|null  $markup  готовый reply_markup */
    public function say(int $chatId, string $html, ?array $markup = null, array $extra = []): int
    {
        $payload = ['chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML', 'link_preview_options' => json_encode(['is_disabled' => true])]
            + ($markup ? ['reply_markup' => json_encode($markup, JSON_UNESCAPED_UNICODE)] : []) + $extra;

        return (int) $this->call('sendMessage', $payload)->throw()->json('result.message_id');
    }

    /**
     * Документ с подписью (PDF карточки, `Card`): по `file_id` или файлом — тогда `file_id` в ответе.
     *
     * @param  array{0: string, 1: string}|string  $document  file_id или [содержимое, имя]
     * @return array{id: int, file_id: ?string}
     */
    public function document(int $chatId, array|string $document, string $caption, array $extra = []): array
    {
        $payload = ['chat_id' => $chatId, 'caption' => $caption, 'parse_mode' => 'HTML'] + $extra;
        $response = is_string($document)
            ? $this->call('sendDocument', $payload + ['document' => $document])
            : $this->call('sendDocument', $payload, ['document' => $document]);
        $result = $response->throw()->json('result');

        return ['id' => (int) ($result['message_id'] ?? 0), 'file_id' => $result['document']['file_id'] ?? null];
    }

    /** «Отправляет файл» — пока грузится PDF, который Telegram ещё не видел. */
    public function action(int $chatId, string $action): void
    {
        try {
            $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
        } catch (Throwable) {
        }
    }

    public function updates(int $offset, int $timeout): array
    {
        return $this->guard(fn () => $this->client($timeout + 10)->get('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => json_encode(['message', 'callback_query', 'my_chat_member'])])->throw()->json('result', []));
    }

    /** Описание перед «Запустить», короткое описание и меню команд (`offers-bot:profile`). */
    public function profile(string $description, string $short): void
    {
        $this->setProfile($description, $short);
        $this->setCommands();
    }

    /** Тихо: сбой отправки не должен ронять разбор обновления — в лог, дальше. */
    public function quietly(callable $send): mixed
    {
        try {
            return $send();
        } catch (Throwable $e) {
            Log::warning('Бот предложений: не отправилось', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function deleteQuietly(int $chatId, int $messageId): void
    {
        $this->quietly(fn () => $this->delete($chatId, $messageId));
    }

    /**
     * Убрать карточку ушедшего предложения. Telegram удаляет только сообщения моложе 48 часов — старше остаётся
     * переписать подпись на «Ушло из продажи» и снять кнопку (цена у менеджера больше не висит).
     * false — не вышло ни то, ни другое (бот остановлен, сообщение уже удалено человеком).
     */
    public function retract(int $chatId, int $messageId): bool
    {
        try {
            $this->delete($chatId, $messageId);

            return true;
        } catch (Throwable) {
        }
        // PDF — подписью, карточка без кадров — текстом.
        foreach (['editMessageCaption' => 'caption', 'editMessageText' => 'text'] as $method => $field) {
            try {
                $this->call($method, ['chat_id' => $chatId, 'message_id' => $messageId, $field => 'Ушло из продажи',
                    'reply_markup' => json_encode(['inline_keyboard' => []])])->throw();

                return true;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
        Log::warning('Бот предложений: карточка не убралась', ['chat' => $chatId, 'message' => $messageId, 'error' => $error ?? null]);

        return false;
    }
}
