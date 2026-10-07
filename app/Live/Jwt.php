<?php

namespace App\Live;

/** Токены Mercure — HS256 руками, без библиотеки: две функции. */
final class Jwt
{
    public static function publisher(): string
    {
        return self::sign((string) config('xcar.mercure.publisher_key'), ['mercure' => ['publish' => ['*']]]);
    }

    /** `exp` — хаб сам закроет поток в эту секунду (менеджер под замком: конец окна). */
    public static function subscriber(array $topics, ?int $exp = null): string
    {
        return self::sign((string) config('xcar.mercure.subscriber_key'), ['mercure' => ['subscribe' => $topics]] + ($exp ? ['exp' => $exp] : []));
    }

    /** Темы из токена подписчика — чтобы не переписывать cookie на каждом ответе. */
    public static function topicsOf(?string $token): ?array
    {
        return self::claims($token)['mercure']['subscribe'] ?? null;
    }

    public static function expOf(?string $token): ?int
    {
        return self::claims($token)['exp'] ?? null;
    }

    private static function claims(?string $token): array
    {
        $parts = explode('.', (string) $token);

        return count($parts) === 3 ? (array) json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : [];
    }

    private static function sign(string $key, array $claims): string
    {
        $encode = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $body = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode($claims);

        return $body.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $key, true)), '+/', '-_'), '=');
    }
}
