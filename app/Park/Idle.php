<?php

namespace App\Park;

/** Светофор простоя: до `park_idle_days` — обычный, до удвоенного — тревожный, дальше — красный. Одно место для порогов. */
final class Idle
{
    public static function warn(): int
    {
        return (int) config('xcar.park_idle_days', 30);
    }

    public static function danger(): int
    {
        return (int) config('xcar.park_idle_danger_days', self::warn() * 2);
    }

    public static function tone(?int $days): ?string
    {
        return match (true) {
            $days === null => null,
            $days >= self::danger() => 'danger',
            $days >= self::warn() => 'urgent',
            default => null,
        };
    }
}
