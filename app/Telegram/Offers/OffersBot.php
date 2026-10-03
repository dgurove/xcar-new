<?php

namespace App\Telegram\Offers;

use App\Telegram\Bot;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Второй бот — @xcar_offers_bot, предложения менеджерам по образцу «Дайвинчика» (владелец 03.10.2026). Тот же клиент
 * Bot API, что у основного (IPv6, повторы, 429), но свой токен и кэш, без журнала CRM: `telegram_chats` рассчитан на
 * одного бота. Сообщения — HTML; клавиатуры собирает `Keys`. Возвращает id сообщения в Telegram.
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

    /** У этого бота владельца нет: вопросы идут админам, подписанным на него (`Questions`). */
    public function ownerChatId(): ?int
    {
        return null;
    }

    /** Кнопки «Открыть в XCar» — входом через Telegram, когда домен выставлен в BotFather. */
    public function logsIn(): bool
    {
        return (bool) $this->config('login');
    }

    public function lanes(): int
    {
        return max(1, (int) $this->config('lanes'));
    }

    /** @param  array<string, mixed>|null  $markup  готовый reply_markup (`Keys`) */
    public function say(int $chatId, string $html, ?array $markup = null, array $extra = []): int
    {
        $payload = ['chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML', 'link_preview_options' => json_encode(['is_disabled' => true])]
            + ($markup ? ['reply_markup' => json_encode($markup, JSON_UNESCAPED_UNICODE)] : []) + $extra;

        return (int) $this->call('sendMessage', $payload)->throw()->json('result.message_id');
    }

    /**
     * Фото с подписью: по `file_id`, если кадр уже был у Telegram, иначе файлом — и `file_id` в кэш.
     *
     * @param  array{0: string, 1: string}|string  $photo  file_id или [содержимое, имя]
     * @return array{id: int, file_id: ?string}
     */
    public function photo(int $chatId, array|string $photo, string $caption, ?array $markup = null, array $extra = []): array
    {
        $payload = ['chat_id' => $chatId, 'caption' => $caption, 'parse_mode' => 'HTML']
            + ($markup ? ['reply_markup' => json_encode($markup, JSON_UNESCAPED_UNICODE)] : []) + $extra;
        $response = is_string($photo)
            ? $this->call('sendPhoto', $payload + ['photo' => $photo])
            : $this->call('sendPhoto', $payload, ['photo' => $photo]);
        $result = $response->throw()->json('result');
        $sizes = $result['photo'] ?? [];

        return ['id' => (int) ($result['message_id'] ?? 0), 'file_id' => $sizes ? (string) end($sizes)['file_id'] : null];
    }

    /** «Печатает» / «отправляет фото» — пока грузится кадр, который Telegram ещё не видел. */
    public function action(int $chatId, string $action): void
    {
        try {
            $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
        } catch (Throwable) {
        }
    }

    /** Отметка на сообщении человека: «принято» без лишнего сообщения в чате. */
    public function react(int $chatId, int $messageId, string $emoji): void
    {
        $this->call('setMessageReaction', ['chat_id' => $chatId, 'message_id' => $messageId, 'reaction' => json_encode([['type' => 'emoji', 'emoji' => $emoji]], JSON_UNESCAPED_UNICODE)])->throw();
    }

    public function updates(int $offset, int $timeout): array
    {
        return $this->guard(fn () => $this->client($timeout + 10)->get('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => json_encode(['message', 'callback_query', 'my_chat_member'])])->throw()->json('result', []));
    }

    /** Описание перед «Запустить», короткое описание и меню команд (`offers-bot:profile`). */
    public function profile(string $description, string $short): void
    {
        $this->setProfile($description, $short);
        $this->call('setMyCommands', ['commands' => json_encode([['command' => 'start', 'description' => 'Главное меню']], JSON_UNESCAPED_UNICODE)])->throw();
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
}
