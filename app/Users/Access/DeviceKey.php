<?php

namespace App\Users\Access;

/**
 * Ключ браузера: ECDSA P-256 из WebCrypto. Закрытый создан неизвлекаемым и лежит в IndexedDB этого браузера, сюда
 * приходит открытый (JWK) и подпись серверного `nonce`. Скопированная cookie без ключа подтвердиться не может.
 * WebCrypto отдаёт подпись как r‖s (64 байта), openssl ждёт DER — переводим сами.
 */
final class DeviceKey
{
    /** SubjectPublicKeyInfo для P-256 до самой точки: id-ecPublicKey, prime256v1, BIT STRING. */
    private const SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * Открытый ключ из запроса, только нужные поля; чужое — null.
     *
     * @return array{kty: string, crv: string, x: string, y: string}|null
     */
    public static function parse(?string $json): ?array
    {
        $jwk = json_decode((string) $json, true);
        if (! is_array($jwk) || ($jwk['kty'] ?? null) !== 'EC' || ($jwk['crv'] ?? null) !== 'P-256') {
            return null;
        }
        foreach (['x', 'y'] as $axis) {
            if (! is_string($jwk[$axis] ?? null) || strlen(self::b64($jwk[$axis])) !== 32) {
                return null;
            }
        }

        return ['kty' => 'EC', 'crv' => 'P-256', 'x' => $jwk['x'], 'y' => $jwk['y']];
    }

    public static function same(?array $a, ?array $b): bool
    {
        return $a && $b && hash_equals($a['x'], $b['x']) && hash_equals($a['y'], $b['y']);
    }

    /** @param array{x: string, y: string} $jwk */
    public static function verify(array $jwk, string $message, ?string $signature): bool
    {
        $raw = self::b64((string) $signature);
        if (strlen($raw) !== 64) {
            return false;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode(hex2bin(self::SPKI_P256)."\x04".self::b64($jwk['x']).self::b64($jwk['y'])), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);

        return $key !== false && openssl_verify($message, self::der($raw), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /** r‖s → SEQUENCE { INTEGER r, INTEGER s }. */
    private static function der(string $raw): string
    {
        $int = function (string $n) {
            $n = ltrim($n, "\x00") ?: "\x00";
            if (ord($n[0]) > 0x7F) {
                $n = "\x00".$n;
            }

            return "\x02".chr(strlen($n)).$n;
        };
        $body = $int(substr($raw, 0, 32)).$int(substr($raw, 32));

        return "\x30".chr(strlen($body)).$body;
    }

    private static function b64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }
}
