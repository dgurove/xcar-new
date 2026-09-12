<?php

namespace App\Telegram;

/** Разобранное нажатие: callback_data у всех тем — `<тема>:<id>:<действие>`. */
final class Press
{
    public function __construct(
        public readonly string $queryId,
        public readonly int $fromId,
        public readonly int $chatId,
        public readonly int $messageId,
        public readonly string $topic,
        public readonly int $id,
        public readonly string $action,
    ) {}

    /** @param array<string, mixed> $query */
    public static function parse(array $query): ?self
    {
        if (preg_match('/^([a-z]+):(\d+):([a-z]+)$/', (string) ($query['data'] ?? ''), $m) !== 1) {
            return null;
        }

        return new self(
            queryId: (string) ($query['id'] ?? ''),
            fromId: (int) data_get($query, 'from.id', 0),
            chatId: (int) data_get($query, 'message.chat.id', 0),
            messageId: (int) data_get($query, 'message.message_id', 0),
            topic: $m[1],
            id: (int) $m[2],
            action: $m[3],
        );
    }
}
