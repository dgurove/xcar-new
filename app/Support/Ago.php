<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Сколько прошло — одним словом для таблиц: «сейчас», «5 мин», «3 ч», «2 д»,
 * «3 нед», «2 мес», «1 г». Точная дата — в title у <time>. Сколько осталось до срока — left().
 * Когда — коротко, одним правилом на приложение — stamp(): «12:30» сегодня, «9 окт» в этом году, «9 окт 2025» раньше.
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
            $s < 86400 * 7 => intdiv($s, 86400).' д',
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
        $text = $s >= 60 ? self::span($s) : '0:'.str_pad((string) $s, 2, '0', STR_PAD_LEFT);

        return $word === '' ? $text : "{$word} {$text}";
    }

    /** Сколько прошло с момента — тем же видом, что `left`: «2 д 14 ч», «3 ч 5 мин», «12 мин». */
    public static function since(Carbon $from): string
    {
        return self::span(max(60, (int) floor($from->diffInSeconds(now(), false))));
    }

    private static function span(int $s): string
    {
        [$d, $h, $m] = [intdiv($s, 86400), intdiv($s % 86400, 3600), intdiv($s % 3600, 60)];

        return match (true) {
            $d > 0 => $h ? "{$d} д {$h} ч" : "{$d} д",
            $h > 0 => $m ? "{$h} ч {$m} мин" : "{$h} ч",
            default => "{$m} мин",
        };
    }

    /**
     * Короткая дата строки, ленты и чата (16.10.2026: около двадцати мест писали её сами, и старый год выходил то
     * «09.10.25», то «9 окт 2025»): сегодня — время, в этом году — день и месяц, раньше — с годом. С временем —
     * «9 окт, 12:30» (сегодня — только время).
     */
    public static function stamp(?\DateTimeInterface $at, bool $time = false): string
    {
        if (! $at) return '';
        $at = Carbon::instance($at);
        if ($at->isToday()) return $at->format('H:i');
        $day = $at->isCurrentYear() ? 'j M' : 'j M Y';

        return $at->translatedFormat($time ? "{$day}, H:i" : $day);
    }

    /**
     * День без «сегодня — время» (дата счёта, период, дата из журнала): «9 окт», в другом году — «9 окт 2025»; с временем
     * — «9 окт, 14:05».
     */
    public static function day(?\DateTimeInterface $at, bool $time = false): string
    {
        if (! $at) return '';
        $at = Carbon::instance($at);

        return $at->translatedFormat(($at->isCurrentYear() ? 'j M' : 'j M Y').($time ? ', H:i' : ''));
    }

    public static function time(Carbon $at, string $class = ''): string
    {
        return '<time datetime="'.$at->toIso8601String().'" title="'.e($at->translatedFormat('j M, H:i')).'" class="nums '.$class.'">'.self::short($at).'</time>';
    }
}
