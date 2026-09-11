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

    public static function containerClass(?string $view): string
    {
        return match ($view) {
            self::LIST => 'cards cards--list',
            self::GRID => 'cards cards--grid',
            default => 'cards',
        };
    }
}
