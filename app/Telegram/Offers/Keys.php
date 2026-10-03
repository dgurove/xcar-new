<?php

namespace App\Telegram\Offers;

/**
 * Клавиатуры бота предложений. Нижняя (`reply`) живёт под полем ввода, пока её не сменят: её ставит одно сообщение
 * («✨🔍», меню), дальше карточки идут без неё — Telegram не даёт в одном сообщении и нижнюю клавиатуру, и кнопки
 * под ним. Кнопки под сообщением (`inline`) — ссылки, вход через Telegram, «скопировать».
 */
final class Keys
{
    public const SHOW = '👍 1';

    public const LATER = '💤 2';

    public const NEXT = '➡️';

    public const PIN = '📌';

    public const ASK = '💬';

    public const SLEEP = '💤';

    public const MENU = 'Главное меню';

    public const BACK = 'Назад';

    public const NO_LABEL = 'Без названия';

    /** @param  list<list<string>>  $rows */
    public static function reply(array $rows): array
    {
        return [
            'keyboard' => array_map(fn ($row) => array_map(fn ($text) => ['text' => $text], $row), $rows),
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /** @param  list<list<array<string, mixed>>>  $rows */
    public static function inline(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    public static function feed(): array
    {
        return self::reply([[self::NEXT, self::PIN, self::ASK, self::SLEEP]]);
    }

    public static function prompt(): array
    {
        return self::reply([[self::SHOW, self::LATER]]);
    }

    public static function menu(bool $subscribed): array
    {
        return self::reply([$subscribed ? ['1 🚀', '2', '3'] : ['1 🚀', '2']]);
    }

    public static function toMenu(): array
    {
        return self::reply([[self::MENU]]);
    }

    /** Кнопка на сайт: входом через Telegram (`login_url`, нужен домен в BotFather) или простой ссылкой. */
    public static function site(string $text, string $url, bool $login): array
    {
        return $login ? ['text' => $text, 'login_url' => ['url' => $url, 'request_write_access' => false]] : ['text' => $text, 'url' => $url];
    }
}
