<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Сколько прошло — одним словом для таблиц: «сейчас», «5 мин», «3 ч», «2 дн»,
 * «3 нед», «2 мес», «1 г». Точная дата — в title у <time>.
 */
final class Ago
{
    public static function short(Carbon $at): string
    {
        $s = max(0, (int) $at->diffInSeconds(now()));

        return match (true) {
            $s < 60 => 'сейчас',
            $s < 3600 => intdiv($s, 60).' мин',
            $s < 86400 => intdiv($s, 3600).' ч',
            $s < 86400 * 7 => intdiv($s, 86400).' дн',
            $s < 86400 * 30 => intdiv($s, 86400 * 7).' нед',
            $s < 86400 * 365 => max(1, intdiv($s, 86400 * 30)).' мес',
            default => intdiv($s, 86400 * 365).' г',
        };
    }

    public static function time(Carbon $at, string $class = ''): string
    {
        return '<time datetime="'.$at->toIso8601String().'" title="'.e($at->translatedFormat('j M, H:i')).'" class="nums '.$class.'">'.self::short($at).'</time>';
    }
}
