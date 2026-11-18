<?php

namespace App\Support;

/**
 * Подпись кнопки, что открывает шторку, меню или переспрос, — с многоточием (18.11.2026, владелец: «многоточий у кнопок не хватает,
 * например у „Забрал“ — там же меню открывается»): «Забрал…», «Оплатил наличными…». Вопрос и готовое многоточие не трогаем.
 */
final class Opens
{
    public static function label(?string $label, bool $opens = true): string
    {
        $label = trim((string) $label);

        return $opens && $label !== '' && ! preg_match('/[…?]$/u', $label) ? rtrim($label, '.').'…' : $label;
    }
}
