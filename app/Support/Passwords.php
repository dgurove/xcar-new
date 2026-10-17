<?php

namespace App\Support;

/**
 * Пароль, который человек получит сообщением и наберёт руками (18.10.2026, владелец: «создавать человека самому, чтобы
 * пароль сгенерился и мы отправили данные»): восемь знаков Crockford base32 без i, l, o, u двумя четвёрками — «7kq2-m9px»,
 * 40 бит. Строчными: поле пароля на телефоне их и набирает, заглавные пришлось бы переключать. Дефис — часть пароля.
 */
final class Passwords
{
    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    public static function readable(): string
    {
        $chars = '';
        foreach (str_split(random_bytes(8)) as $byte) {
            $chars .= self::ALPHABET[ord($byte) & 31];
        }

        return substr($chars, 0, 4).'-'.substr($chars, 4);
    }
}
