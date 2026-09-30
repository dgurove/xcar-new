<?php

namespace App\Telegram;

use App\Live\Publisher;
use App\Live\Topics;
use App\Users\User;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Журнал переписки бота (Настройки → «Бот Telegram»). Пишется в двух узких местах, через которые проходит
 * всё: исходящее — Bot::call() после ответа Telegram, входящее — UpdateHandler::handle() до разбора.
 * Кто и зачем отправил, журналу знать не нужно — новые сообщения бота попадают сюда сами.
 * Журнал — наблюдение: его сбой пишется в лог и никогда не мешает отправке или разбору.
 * Один на запрос или джобу (Scoped): `by()` помечает автором сотрудника всё, что он отправил из CRM.
 */
#[Scoped]
final class Journal
{
    private const SENDS = ['sendMessage' => 'text', 'sendPhoto' => 'photo', 'sendDocument' => 'document'];

    private ?int $author = null;

    public function __construct(private Publisher $publish) {}

    /** Всё отправленное внутри `$send` написал сотрудник, а не бот сам. */
    public function by(User $author, callable $send): mixed
    {
        $this->author = $author->id;
        try {
            return $send();
        } finally {
            $this->author = null;
        }
    }

    /**
     * Telegram принял вызов.
     *
     * @param  array<string, mixed>  $result
     */
    public function sent(string $method, array $payload, array $result): ?ChatMessage
    {
        return $this->safely(function () use ($method, $payload, $result) {
            if (isset(self::SENDS[$method])) {
                return $this->outgoing($method, $payload, $result, null);
            }
            if ($method === 'editMessageText') {
                return $this->edited($payload);
            }
            if ($method === 'deleteMessage') {
                return $this->touch($payload, fn (ChatMessage $m) => $m->forceFill(['deleted_at' => now()]));
            }

            return null;
        });
    }

    /** Отправка не дошла (4xx: бот заблокирован, чат удалён) — в переписке остаётся пузырь с причиной. */
    public function failed(string $method, array $payload, string $error): ?ChatMessage
    {
        return isset(self::SENDS[$method]) ? $this->safely(fn () => $this->outgoing($method, $payload, [], $error)) : null;
    }

    /** @param array<string, mixed> $update */
    public function received(array $update): void
    {
        $this->safely(function () use ($update) {
            if (is_array($message = $update['message'] ?? null)) {
                $this->incoming($message);
            } elseif (is_array($message = $update['edited_message'] ?? null)) {
                $this->touch(['chat_id' => data_get($message, 'chat.id'), 'message_id' => $message['message_id'] ?? 0],
                    fn (ChatMessage $m) => $m->forceFill(['text' => $this->content($message)[1], 'edited_at' => now()]));
            } elseif (is_array($query = $update['callback_query'] ?? null)) {
                $this->pressed($query);
            } elseif (is_array($member = $update['my_chat_member'] ?? null)) {
                $this->member($member);
            }
        });
    }

    private function outgoing(string $method, array $payload, array $result, ?string $error): ChatMessage
    {
        $chat = $this->chat((int) $payload['chat_id']);
        $file = $result['document'] ?? (isset($result['photo']) ? end($result['photo']) : null);
        $reply = json_decode((string) ($payload['reply_parameters'] ?? ''), true)['message_id'] ?? null;
        $message = $chat->messages()->create([
            'message_id' => $result['message_id'] ?? null,
            'direction' => ChatMessage::OUT,
            'kind' => self::SENDS[$method],
            'text' => $payload['text'] ?? $payload['caption'] ?? null,
            'keyboard' => $this->keyboard($payload),
            'reply_to' => $reply ? $chat->messages()->where('message_id', $reply)->value('id') : null,
            'file_id' => $file['file_id'] ?? null,
            'file_unique_id' => $file['file_unique_id'] ?? null,
            'file_name' => $file['file_name'] ?? null,
            'file_mime' => $file['mime_type'] ?? ($method === 'sendPhoto' ? 'image/jpeg' : null),
            'file_size' => $file['file_size'] ?? null,
            'author_id' => $this->author,
            'failed' => $error ? mb_strimwidth($error, 0, 250, '…') : null,
        ]);
        $this->posted($chat, $message);

        return $message;
    }

    /** Бот переписал своё сообщение (решение по кнопке, «Вошли»): в переписке — новый текст и кнопки. */
    private function edited(array $payload): ?ChatMessage
    {
        return $this->touch($payload, fn (ChatMessage $m) => $m->forceFill([
            'text' => $payload['text'] ?? $m->text,
            'keyboard' => $this->keyboard($payload),
            'edited_at' => now(),
        ]));
    }

    private function touch(array $payload, callable $change): ?ChatMessage
    {
        $message = ChatMessage::where('chat_id', (int) ($payload['chat_id'] ?? 0))->where('message_id', (int) ($payload['message_id'] ?? 0))->first();
        if (! $message) {
            return null;
        }
        $change($message);
        $message->save();
        ($this->publish)(Topics::ADMIN, 'tg-chat-edit', ['chat' => $message->chat_id, 'seq' => $message->id]);

        return $message;
    }

    private function incoming(array $message): void
    {
        $chat = $this->chat((int) data_get($message, 'chat.id'), $message['from'] ?? $message['chat'] ?? null);
        [$kind, $text, $file] = $this->content($message);
        $replyTo = data_get($message, 'reply_to_message.message_id');
        $row = $chat->messages()->firstOrNew(['message_id' => $message['message_id'] ?? null]);
        if ($row->exists) {
            return;
        }
        $row->fill([
            'direction' => ChatMessage::IN,
            'kind' => $kind,
            'text' => $text,
            'reply_to' => $replyTo ? $chat->messages()->where('message_id', $replyTo)->value('id') : null,
            'file_id' => $file['file_id'] ?? null,
            'file_unique_id' => $file['file_unique_id'] ?? null,
            'file_name' => $file['file_name'] ?? null,
            'file_mime' => $file['mime_type'] ?? null,
            'file_size' => $file['file_size'] ?? null,
        ]);
        $row->created_at = isset($message['date']) ? Carbon::createFromTimestamp((int) $message['date'], config('app.timezone')) : now();
        $row->save();
        $this->posted($chat, $row);
    }

    /**
     * Что прислали: тип, текст и файл. Ссылка /start с токеном — «/start»: токен — секрет привязки и входа.
     *
     * @return array{0: string, 1: ?string, 2: ?array<string, mixed>}
     */
    private function content(array $m): array
    {
        $caption = $m['caption'] ?? null;

        return match (true) {
            isset($m['text']) => ['text', preg_replace('~^(/start(?:@\w+)?)\s+\S+$~', '$1', (string) $m['text']), null],
            isset($m['photo']) => ['photo', $caption, ['mime_type' => 'image/jpeg'] + end($m['photo'])],
            isset($m['document']) => ['document', $caption, $m['document']],
            isset($m['voice']) => ['voice', null, $m['voice']],
            isset($m['audio']) => ['document', $caption, ['file_name' => $m['audio']['file_name'] ?? 'Аудио'] + $m['audio']],
            isset($m['video']) => ['document', $caption, ['file_name' => $m['video']['file_name'] ?? 'Видео'] + $m['video']],
            isset($m['video_note']) => ['document', null, ['file_name' => 'Видеосообщение', 'mime_type' => 'video/mp4'] + $m['video_note']],
            isset($m['sticker']) => ['sticker', $m['sticker']['emoji'] ?? null, empty($m['sticker']['is_animated']) && empty($m['sticker']['is_video']) ? ['mime_type' => 'image/webp'] + $m['sticker'] : null],
            isset($m['contact']) => ['text', 'Контакт: '.trim(($m['contact']['first_name'] ?? '').' '.($m['contact']['last_name'] ?? '')).', +'.ltrim((string) ($m['contact']['phone_number'] ?? ''), '+'), null],
            isset($m['location']) => ['text', 'Геопозиция: https://maps.yandex.ru/?pt='.$m['location']['longitude'].','.$m['location']['latitude'].'&z=16', null],
            default => ['text', 'Сообщение, которое бот не показывает', null],
        };
    }

    /** Нажатие кнопки под сообщением бота: в переписке — подпись кнопки, как её видел человек. */
    private function pressed(array $query): void
    {
        $chatId = (int) data_get($query, 'message.chat.id', data_get($query, 'from.id'));
        $chat = $this->chat($chatId, $query['from'] ?? null);
        $under = $chat->messages()->where('message_id', (int) data_get($query, 'message.message_id'))->first();
        $data = (string) ($query['data'] ?? '');
        $label = collect($under?->keyboard ?? [])->flatten(1)->firstWhere('callback_data', $data)['text'] ?? $data;
        $row = $chat->messages()->create(['direction' => ChatMessage::IN, 'kind' => 'press', 'text' => $label, 'reply_to' => $under?->id]);
        $this->posted($chat, $row);
    }

    private function member(array $update): void
    {
        if (data_get($update, 'chat.type') !== 'private') {
            return;
        }
        $left = in_array(data_get($update, 'new_chat_member.status'), ['kicked', 'left'], true);
        $chat = $this->chat((int) data_get($update, 'chat.id'), $update['from'] ?? null);
        $chat->forceFill(['left_at' => $left ? now() : null])->save();
        $row = $chat->messages()->create(['direction' => ChatMessage::IN, 'kind' => 'system', 'text' => $left ? 'Остановил бота' : 'Запустил бота']);
        $this->posted($chat, $row);
    }

    /** Чат по chat_id: заводится при первом сообщении; имя и @username — из последнего входящего. */
    private function chat(int $id, ?array $from = null): Chat
    {
        $chat = Chat::firstOrNew(['id' => $id]);
        if ($from) {
            $name = trim(($from['first_name'] ?? $from['title'] ?? '').' '.($from['last_name'] ?? ''));
            $chat->name = $name !== '' ? $name : $chat->name;
            $chat->username = $from['username'] ?? $chat->username;
        }
        if (! $chat->user_id) {
            $chat->user_id = User::where('telegram_chat_id', $id)->value('id');
        }
        $chat->save();

        return $chat;
    }

    private function posted(Chat $chat, ChatMessage $message): void
    {
        $chat->forceFill(['last_message_at' => $message->created_at ?? now()])->save();
        ($this->publish)(Topics::ADMIN, 'tg-chat', ['chat' => $chat->id, 'seq' => $message->id]);
        ($this->publish)->refresh(Topics::ADMIN, ['/settings/telegram']);
    }

    /** @return list<list<array<string, string>>>|null */
    private function keyboard(array $payload): ?array
    {
        return json_decode((string) ($payload['reply_markup'] ?? ''), true)['inline_keyboard'] ?? null;
    }

    private function safely(callable $record): mixed
    {
        try {
            return $record();
        } catch (Throwable $e) {
            Log::warning('Telegram: журнал не записал', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
