<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Сводка или напоминание — раз на слот (день, человек, вендор). Отметка «отправлено» — только после удачной отправки, на время
 * отправки — короткий замок (26.11.2026: отметку ставили до отправки, и задачу, убитую выкладкой между ними, слот терял до
 * следующего — до 15 часов). Упало посреди — замок истекает, следующий тик отправит. `atomic` — отправка и свои пометки одной
 * транзакцией: очередь в той же базе, задания встают все или ни одного.
 */
final class SentOnce
{
    /** Сколько держится замок упавшей отправки, секунд. */
    public const LOCK = 300;

    /** @return mixed что вернула отправка; false — уже отправлено или отправляет другой */
    public static function run(string $key, \DateTimeInterface|int $ttl, callable $send, bool $atomic = true): mixed
    {
        if (Cache::has($key)) {
            return false;
        }
        $lock = Cache::lock($key.':sending', self::LOCK);
        if (! $lock->get()) {
            return false;
        }
        try {
            if (Cache::has($key)) {
                return false;
            }
            $result = $atomic ? DB::transaction($send) : $send();
            Cache::put($key, true, $ttl);

            return $result;
        } finally {
            $lock->release();
        }
    }
}
