<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Вид списка: без выбора — строки на телефоне и плитки от 640 (решает CSS),
 * `?vid=list` и `?vid=grid` — явный выбор, он живёт в адресе.
 */
final class ListView
{
    public const PARAM = 'vid';

    public const LIST = 'list';

    public const GRID = 'grid';

    public static function fromRequest(Request $request): ?string
    {
        $vid = $request->query(self::PARAM);

        return in_array($vid, [self::LIST, self::GRID], true) ? $vid : null;
    }

    /**
     * sizes для кадра карточки: в строке кадр 4.5–6 rem, и без этого браузер
     * на телефоне берёт оригинал 1600 px под каждую из двадцати карточек.
     */
    public static function sizes(?string $view): string
    {
        return match ($view) {
            self::LIST => '(min-width: 640px) 6rem, 4.5rem',
            self::GRID => '(min-width: 1024px) 320px, (min-width: 640px) 45vw, 100vw',
            default => '(min-width: 1024px) 320px, (min-width: 640px) 45vw, 4.5rem',
        };
    }

    public static function containerClass(?string $view): string
    {
        return match ($view) {
            self::LIST => 'cards cards--list',
            self::GRID => 'cards cards--grid',
            default => 'cards',
        };
    }
}
