<?php

namespace App\Telegram\Offers;

/**
 * Проверка подписи кнопки входа Telegram (`login_url`): Telegram дописывает к адресу id, имя, auth_date и hash —
 * HMAC-SHA256 строки «ключ=значение» по алфавиту через перевод строки, ключ — SHA256 токена бота. Берём только поля
 * Telegram: свой параметр подписью не покрыт. Свежесть — сутки: адрес с подписью остаётся в истории браузера.
 */
final class LoginUrl
{
    private const FIELDS = ['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date'];

    /** @return int|null id пользователя Telegram, если подпись верна и свежая */
    public static function verify(array $query, string $token, int $maxAge = 86400): ?int
    {
        $hash = (string) ($query['hash'] ?? '');
        $data = array_intersect_key($query, array_flip(self::FIELDS));
        if ($hash === '' || ! isset($data['id'], $data['auth_date']) || $token === '') {
            return null;
        }
        ksort($data);
        $check = implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys($data), $data));
        $expected = hash_hmac('sha256', $check, hash('sha256', $token, true));
        if (! hash_equals($expected, $hash) || abs(time() - (int) $data['auth_date']) > $maxAge) {
            return null;
        }

        return (int) $data['id'];
    }
}
