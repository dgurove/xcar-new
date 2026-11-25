<?php

namespace App\Support;

/**
 * old() для скалярного поля, набранного руками (`<input value="{{ … }}">` мимо `x-ui.field`): чужая форма той же страницы с тем же
 * именем набором (`value[ключ]`) возвращала массив, и `htmlspecialchars` ронял страницу 500 (25.11.2026, «Показать менеджеру»).
 */
final class OldScalar
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $old = old($key, $default);

        return is_array($old) ? $default : $old;
    }
}
