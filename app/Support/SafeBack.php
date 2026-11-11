<?php

namespace App\Support;

/**
 * «Назад» из формы (`back` — путь, откуда нажали) — только на свой путь (11.11.2026: было два правила, и у одного `/\host`
 * уводил на чужой сайт — браузер читает обратный слэш как `//`): начинается с одного `/`, без `//`, `\`, переводов строки и
 * не длиннее 2000; иначе null — вызывающий берёт свой адрес.
 */
final class SafeBack
{
    public static function path(mixed $back): ?string
    {
        $back = is_string($back) ? $back : '';

        return $back !== '' && strlen($back) <= 2000 && preg_match('#^/(?![/\\\\])[^\\\\\r\n]*$#', $back) === 1 ? $back : null;
    }
}
