<?php

namespace App\Users;

use Illuminate\Support\Str;

/**
 * Одноразовая ссылка с токеном: наружу уходит открытый токен, в базе (`token_hash`) — только sha256.
 * Общее у ссылки на новый пароль и входа за человека.
 */
trait HashedToken
{
    /** @return array{0: string, 1: string} открытый токен и его хэш */
    public static function newToken(): array
    {
        $plain = Str::random(40);

        return [$plain, hash('sha256', $plain)];
    }

    public static function byToken(string $plain): ?static
    {
        return static::where('token_hash', hash('sha256', $plain))->first();
    }
}
