<?php

namespace App\Notifications;

use RuntimeException;

/**
 * Канал не достучался по временной причине (сеть, DNS, 429, 5xx) — задание уведомления повторится (`Notice::backoff`:
 * 1, 5, 15 минут). 19.11.2026: за две недели у менеджеров ~30 доставок Telegram и пуша пропали на минутных сбоях DNS, а
 * каналы глушили ошибку — повтора не было. Попытка пишется строкой журнала `retry`, последняя неудача — `failed`
 * (`Notice::failed`). В отчёт об ошибках не идёт (`bootstrap/app.php` `dontReport`): это ожидаемый повтор, не сбой кода.
 */
final class RetryDelivery extends RuntimeException
{
    /**
     * Номер попытки задания, что сейчас выполняется (`Queue::before` в `AppServiceProvider`): канал номера не знает, а
     * считать строки журнала на каждую отправку — лишний запрос. Вне очереди — 1.
     */
    public static int $attempt = 1;

    public function __construct(public readonly string $channel, public readonly int $userId, public readonly string $detail)
    {
        parent::__construct("{$channel}: {$detail}");
    }

    /** Попытка — строкой журнала (`retry`, номер попытки в причине), и дальше — исключение для очереди. */
    public static function after(Notice $notice, int $userId, string $channel, string $detail): self
    {
        Delivery::log($notice, (string) $notice->id, $userId, $channel, 'retry', 'попытка '.self::$attempt.': '.$detail);

        return new self($channel, $userId, $detail);
    }

    /** Сколько попыток уже не вышло — для «с 2-й попытки». */
    public static function retries(): int
    {
        return max(0, self::$attempt - 1);
    }
}
