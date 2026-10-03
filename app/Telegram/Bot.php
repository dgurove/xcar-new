<?php

namespace App\Telegram;

use App\Users\Role;
use App\Users\User;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Бот xcar: владельцу и админам — сообщения с кнопками решения, менеджерам — уведомления по сделкам,
 * привязка чата и вход (`/start`, StartLink). Сообщения с кнопками и ответы на нажатия. Без SDK — четыре
 * метода Bot API через Http. До api.telegram.org с сервера доходит только
 * IPv6 — клиент это и просит. `send` бросает исключение (его зовёт джоба,
 * повтор — дело очереди), ответы на нажатия ошибку гасят: их зовёт опрос.
 * Всё, что уходит через `call`, попадает в журнал переписки (Journal) — мимо него не пишем.
 */
class Bot
{
    /** Журнал переписки — у основного бота; у второго (`Offers\OffersBot`) его нет: `telegram_chats` — один бот. */
    public function __construct(protected ?Journal $journal) {}

    /** Ключ настроек в `xcar.telegram` и приставка кэша: у бота предложений свои (`offers`, `offers-bot`). */
    protected function config(string $key): mixed
    {
        return config('xcar.telegram.'.$key);
    }

    protected function cacheKey(string $key): string
    {
        return 'telegram:'.$key;
    }

    public function configured(): bool
    {
        return $this->token() !== '';
    }

    public function ownerChatId(): ?int
    {
        $id = trim((string) $this->config('owner_chat_id'));

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
        $name = trim((string) $this->config('username'), " @\t");
        if ($name !== '' || ! $this->configured()) {
            return $name ?: null;
        }
        if ($name = Cache::get($this->cacheKey('username'))) {
            return $name;
        }
        // Неудачу помним десять минут: страница не должна ждать Telegram на каждом открытии.
        if (Cache::has($this->cacheKey('username:failed'))) {
            return null;
        }
        try {
            $name = (string) $this->guard(fn () => $this->client(5)->get('getMe')->throw()->json('result.username'));
        } catch (Throwable $e) {
            Log::warning('Telegram: getMe не ответил', ['error' => $e->getMessage()]);
        }
        if (! $name) {
            Cache::put($this->cacheKey('username:failed'), true, 600);

            return null;
        }
        Cache::forever($this->cacheKey('username'), $name);

        return $name;
    }

    /** Ссылка на бота с параметром /start. */
    public function startUrl(string $payload): ?string
    {
        $name = $this->username();

        return $name ? 'https://t.me/'.$name.'?start='.$payload : null;
    }

    /**
     * `silent` — без звука (тихие часы), `replyTo` — ответ на сообщение (id в Telegram). Возвращает строку журнала.
     *
     * @param  array<int, array<int, array{text: string, callback_data?: string, url?: string}>>|null  $keyboard
     */
    public function send(int $chatId, string $text, ?array $keyboard = null, bool $silent = false, ?int $replyTo = null): ?ChatMessage
    {
        $payload = $this->payload($chatId, $text, $keyboard) + ($silent ? ['disable_notification' => 'true'] : []) + $this->replyTo($replyTo);

        return $this->logged($chatId, $this->call('sendMessage', $payload));
    }

    /** Файл из CRM: картинка — фото, остальное — документом. */
    public function sendFile(int $chatId, UploadedFile $file, ?string $caption = null, ?int $replyTo = null): ?ChatMessage
    {
        $photo = str_starts_with((string) $file->getMimeType(), 'image/') && in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp'], true);
        [$method, $field] = $photo ? ['sendPhoto', 'photo'] : ['sendDocument', 'document'];
        $payload = ['chat_id' => $chatId] + ($caption ? ['caption' => $caption, 'parse_mode' => 'HTML'] : []) + $this->replyTo($replyTo);

        return $this->logged($chatId, $this->call($method, $payload, [$field => $file]));
    }

    public function delete(int $chatId, int $messageId): void
    {
        $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId])->throw();
    }

    /** «Печатает…» в чате человека — пока сотрудник набирает ответ в CRM. */
    public function typing(int $chatId): void
    {
        try {
            $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
        } catch (Throwable) {
        }
    }

    /**
     * Файл из переписки: у Telegram по file_id, один раз — дальше с приватного диска.
     *
     * @return array{0: string, 1: string}|null содержимое и имя на диске
     */
    public function file(string $fileId, string $uniqueId): ?array
    {
        $disk = Storage::disk('private');
        $cached = collect($disk->files('telegram'))->first(fn ($p) => pathinfo($p, PATHINFO_FILENAME) === $uniqueId);
        if ($cached) {
            return [$disk->get($cached), $cached];
        }
        try {
            $path = (string) $this->guard(fn () => $this->client(10)->get('getFile', ['file_id' => $fileId])->throw()->json('result.file_path'));
            $contents = $this->guard(fn () => $this->http()->timeout(60)->get('https://api.telegram.org/file/bot'.$this->token().'/'.$path)->throw()->body());
        } catch (Throwable $e) {
            Log::warning('Telegram: файл не скачался', ['file' => $fileId, 'error' => $e->getMessage()]);

            return null;
        }
        $local = 'telegram/'.$uniqueId.(pathinfo($path, PATHINFO_EXTENSION) ? '.'.pathinfo($path, PATHINFO_EXTENSION) : '');
        $disk->put($local, $contents);

        return [$contents, $local];
    }

    /** Человек закрыл нам чат: заблокировал бота или удалил переписку — слать туда больше нечего. */
    public static function chatGone(Throwable $e): bool
    {
        $status = $e instanceof RequestException ? $e->response->status() : 0;

        return $status === 403 || ($status === 400 && str_contains($e->getMessage(), 'chat not found'));
    }

    /** Переписать своё сообщение; false — Telegram не дал (ошибка в логе). */
    public function edit(int $chatId, int $messageId, string $text, ?array $keyboard = null): bool
    {
        try {
            $this->call('editMessageText', $this->payload($chatId, $text, $keyboard) + ['message_id' => $messageId])->throw();
        } catch (Throwable $e) {
            // Второе нажатие той же кнопки в ту же минуту — текст тот же, это не сбой.
            if (str_contains($e->getMessage(), 'message is not modified')) {
                return true;
            }
            Log::warning('Telegram: сообщение не переписалось', ['chat' => $chatId, 'message' => $messageId, 'error' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    /** Описание и короткое описание бота (telegram:profile). */
    public function setProfile(string $description, string $short): void
    {
        $this->call('setMyDescription', ['description' => $description])->throw();
        $this->call('setMyShortDescription', ['short_description' => $short])->throw();
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
        return $this->guard(fn () => $this->client($timeout + 10)->get('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => json_encode(['message', 'edited_message', 'callback_query', 'my_chat_member'])])->throw()->json('result', []));
    }

    /** Перед опросом: при живом вебхуке getUpdates отвечает 409. */
    public function dropWebhook(): void
    {
        $this->call('deleteWebhook', [])->throw();
    }

    protected function payload(int $chatId, string $text, ?array $keyboard): array
    {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'link_preview_options' => json_encode(['is_disabled' => true])];
        if ($keyboard !== null) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
        }

        return $payload;
    }

    /** Строка журнала для только что отправленного — её пишет call(). */
    private function logged(int $chatId, Response $response): ?ChatMessage
    {
        $id = (int) $response->throw()->json('result.message_id');

        return $id ? ChatMessage::where('chat_id', $chatId)->where('message_id', $id)->first() : null;
    }

    private function replyTo(?int $messageId): array
    {
        return $messageId ? ['reply_parameters' => json_encode(['message_id' => $messageId, 'allow_sending_without_reply' => true])] : [];
    }

    /**
     * Путь до Telegram по IPv6 моргает: четыре попытки, после несостоявшегося соединения — сразу (200 мс), иначе через
     * 1,5 с. Ответ 4xx — не сбой сети, его не повторяем; кроме 429 «слишком часто» — тогда ждём, сколько Telegram скажет (`retry_after`, не дольше 10 с): в 21:00 приём слота
     * закрывается разом, и «Приём закрыт» владельцу уходят пачкой. Каждый вызов — в журнал переписки (если он есть):
     * принятый — как есть, отказ на отправке — пузырём с причиной.
     *
     * @param  array<string, UploadedFile|array{0: string, 1: string}>  $files  файл или [содержимое, имя]
     */
    protected function call(string $method, array $payload, array $files = []): Response
    {
        $request = $this->client($files ? 60 : 20)->retry(4,
            fn (int $attempt, Throwable $e) => self::tooMany($e) ? min(10, (int) ($e->response->json('parameters.retry_after') ?? 1)) * 1000 : ($e instanceof ConnectionException ? 200 : 1500),
            fn (Throwable $e) => ! ($e instanceof RequestException && $e->response->clientError()) || self::tooMany($e));
        foreach ($files as $field => $file) {
            [$contents, $name] = $file instanceof UploadedFile ? [$file->get(), $file->getClientOriginalName()] : $file;
            $request->attach($field, $contents, $name);
        }
        try {
            $response = $this->guard(fn () => ($files ? $request : $request->asForm())->post($method, $payload));
        } catch (RequestException $e) {
            if ($e->response->clientError()) {
                $this->journal?->failed($method, $payload, (string) ($e->response->json('description') ?? $e->getMessage()));
            }
            throw $e;
        }
        if ($response->successful()) {
            $this->journal?->sent($method, $payload, (array) $response->json('result'));
        }

        return $response;
    }

    /**
     * Сбой соединения пишет в текст адрес запроса, а в адресе — токен бота: в лог, в упавшую задачу очереди и в
     * отчёт об ошибке он уйти не должен. Перебрасываем с вычищенным текстом и без исходного исключения внутри.
     */
    protected function guard(callable $request): mixed
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new ConnectionException($this->scrub($e->getMessage()));
        }
    }

    /** Токен — звёздочками: для логов. */
    public function scrub(string $text): string
    {
        $token = $this->token();

        return $token === '' ? $text : str_replace($token, '***', $text);
    }

    private static function tooMany(Throwable $e): bool
    {
        return $e instanceof RequestException && $e->response->status() === 429;
    }

    protected function client(int $timeout): PendingRequest
    {
        return $this->http()->baseUrl('https://api.telegram.org/bot'.$this->token().'/')->timeout($timeout);
    }

    /**
     * До Telegram с сервера каждое десятое новое соединение по IPv6 не устанавливается вовсе (замер 03.10.2026): ждать
     * его 10 с, как по умолчанию, — бот «думает» по 11–23 с. Ждём 2 с и повторяем (`call`), а соединение держим
     * открытым: один обработчик curl на процесс — дорожка живёт часами, и новых соединений почти нет.
     */
    protected function http(): PendingRequest
    {
        return Http::setHandler(self::$curl ??= new CurlHandler)->connectTimeout(2)->withOptions($this->ipOptions());
    }

    private static ?CurlHandler $curl = null;

    /** На сервере до Telegram доходит только IPv6; на маке его может не быть — `TELEGRAM_IPV6=false`. */
    protected function ipOptions(): array
    {
        return config('xcar.telegram.ipv6', true) ? ['force_ip_resolve' => 'v6'] : [];
    }

    protected function token(): string
    {
        return trim((string) $this->config('token'));
    }
}
