<?php

namespace App\Notifications;

/**
 * Итог своего канала для журнала (`Delivery`): пуш и Telegram отвечают им из `send`, `RecordDelivery` пишет строку.
 * `skipped` — слать было некуда (нет подписок на нужном хосте), это не сбой.
 */
final readonly class Sent
{
    private function __construct(public string $status, public ?string $detail = null, public ?int $devices = null) {}

    public static function ok(?int $devices = null): self
    {
        return new self('sent', devices: $devices);
    }

    public static function failed(string $detail, ?int $devices = null): self
    {
        return new self('failed', mb_substr($detail, 0, 160), $devices);
    }

    public static function skipped(string $detail): self
    {
        return new self('skipped', $detail);
    }
}
