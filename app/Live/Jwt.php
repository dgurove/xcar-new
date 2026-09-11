<?php

namespace App\Live;

/** Токены Mercure — HS256 руками, без библиотеки: две функции. */
final class Jwt
{
    public static function publisher(): string
    {
        return self::sign((string) config('xcar.mercure.publisher_key'), ['mercure' => ['publish' => ['*']]]);
    }

    public static function subscriber(array $topics): string
    {
        return self::sign((string) config('xcar.mercure.subscriber_key'), ['mercure' => ['subscribe' => $topics]]);
    }

    /** Темы из токена подписчика — чтобы не переписывать cookie на каждом ответе. */
    public static function topicsOf(?string $token): ?array
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 3) {
            return null;
        }
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return $claims['mercure']['subscribe'] ?? null;
    }

    private static function sign(string $key, array $claims): string
    {
        $encode = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $body = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode($claims);

        return $body.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $key, true)), '+/', '-_'), '=');
    }
}
