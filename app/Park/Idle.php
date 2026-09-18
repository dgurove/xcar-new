<?php

namespace App\Park;

/** Светофор простоя: до 30 дней — обычный, до 60 — тревожный, дальше — красный. Одно место для порогов. */
final class Idle
{
    public const WARN = 30;

    public const DANGER = 60;

    public static function tone(?int $days): ?string
    {
        return match (true) {
            $days === null => null,
            $days >= self::DANGER => 'danger',
            $days >= self::WARN => 'urgent',
            default => null,
        };
    }
}
