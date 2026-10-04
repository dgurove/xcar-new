<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Сколько прошло — одним словом для таблиц: «сейчас», «5 мин», «3 ч», «2 дн»,
 * «3 нед», «2 мес», «1 г». Точная дата — в title у <time>. Сколько осталось до срока — left().
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

    /**
     * Сколько осталось до срока — первый кадр грубого таймера (timer_controller, coarse): «осталось 15 ч 42 мин»,
     * «осталось 2 д 3 ч», «осталось 3 ч» (нулевой хвост не пишем), в последнюю минуту «0:45». word — слово перед числом,
     * пустое — голое число.
     */
    public static function left(Carbon $until, string $word = 'осталось'): string
    {
        $s = max(0, (int) floor(now()->diffInSeconds($until, false)));
        [$d, $h, $m] = [intdiv($s, 86400), intdiv($s % 86400, 3600), intdiv($s % 3600, 60)];
        $text = match (true) {
            $d > 0 => $h ? "{$d} д {$h} ч" : "{$d} д",
            $h > 0 => $m ? "{$h} ч {$m} мин" : "{$h} ч",
            $s >= 60 => "{$m} мин",
            default => '0:'.str_pad((string) $s, 2, '0', STR_PAD_LEFT),
        };

        return $word === '' ? $text : "{$word} {$text}";
    }

    public static function time(Carbon $at, string $class = ''): string
    {
        return '<time datetime="'.$at->toIso8601String().'" title="'.e($at->translatedFormat('j M, H:i')).'" class="nums '.$class.'">'.self::short($at).'</time>';
    }
}
