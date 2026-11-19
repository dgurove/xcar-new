<?php

namespace App\Notifications;

/**
 * Итог своего канала для журнала (`Delivery`): пуш и Telegram отвечают им из `send`, `RecordDelivery` пишет строку.
 * `skipped` — слать было некуда (нет подписок на нужном хосте), это не сбой.
 */
final readonly class Sent
{
    private function __construct(public string $status, public ?string $detail = null, public ?int $devices = null) {}

    /** `$retries` — сколько попыток не вышло до этой (`RetryDelivery`): в журнале «со 2-й попытки». */
    public static function ok(?int $devices = null, int $retries = 0): self
    {
        return new self('sent', $retries > 0 ? 'с '.($retries + 1).'-й попытки' : null, $devices);
    }

    public static function failed(string $detail, ?int $devices = null): self
    {
        return new self('failed', mb_substr($detail, 0, 160), $devices);
    }

    /** Не ушло, но повтор уже поставлен (`Push\\Jobs\\RetryPush`): итог — следующей строкой журнала. */
    public static function retrying(string $detail): self
    {
        return new self('retry', mb_substr($detail, 0, 160));
    }

    public static function skipped(string $detail): self
    {
        return new self('skipped', $detail);
    }
}
