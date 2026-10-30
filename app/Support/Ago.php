<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Сколько прошло — одним словом для таблиц: «сейчас», «5 мин», «3 ч», «2 д»,
 * «3 нед», «2 мес», «1 г». Точная дата — в title у <time>. Сколько осталось до срока — left().
 * Когда — одним правилом на приложение — at() текстом и html() в разметке: «сегодня 07:40», «07.10 07:40», «07.10.2025».
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

    /** Сколько прошло с момента — слитно, как просил владелец: «2д 14ч», «3ч 5мин», «12мин». */
    /** «просрочено 2 д 4 ч» — сколько прошло после срока, тем же видом, что `left` («осталось 1 д 5 ч»). */
    public static function overdue(Carbon $until): string
    {
        return 'просрочено '.self::span(max(60, (int) floor($until->diffInSeconds(now(), false))));
    }

    public static function since(Carbon $from): string
    {
        return str_replace([' д', ' ч', ' мин'], ['д', 'ч', 'мин'], self::span(max(60, (int) floor($from->diffInSeconds(now(), false)))));
    }

    /** Длительность тем же видом: «1 ч 48 мин», «2 д 4 ч» — «обычно открывает через» (`ManagerResponse`). */
    public static function duration(int $seconds): string
    {
        return self::span(max(60, $seconds));
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
     * Дата и время — одна дверь на всё приложение (30.10.2026, владелец: «пишу не „7 окт, 07:40“, а „07.10 07:40“», «везде —
     * и на парковке, и в логистике, и в xcar, и в CRM»): «сегодня 07:40», «вчера 14:19», «07.10 07:40», в другом году
     * «07.10.2025 07:40»; без времени — «сегодня», «07.10». `words: false` — без «сегодня» и «вчера», для фраз, где слово
     * ломает речь («с 30.10 12:00», «до 21.10 23:59»). В разметке — `html` (время приглушено), текстом (уведомления,
     * Telegram, тосты) — это.
     */
    public static function at(?\DateTimeInterface $at, bool $time = false, bool $words = true): string
    {
        if (! $at) {
            return '';
        }
        $at = FastDate::instance($at);
        $day = match (true) {
            $words && $at->isToday() => 'сегодня',
            $words && $at->isYesterday() => 'вчера',
            $at->isCurrentYear() => $at->format('d.m'),
            default => $at->format('d.m.Y'),
        };

        return $time ? $day.' '.$at->format('H:i') : $day;
    }

    /**
     * Та же дата в разметке — одним видом везде (`.when`): `<time>` с полной датой в подсказке, время приглушено (`.when-t`).
     * `short` — строка чата и ленты: сегодня — только время, остальные дни — без времени.
     */
    public static function html(?\DateTimeInterface $at, bool $time = false, bool $words = true, bool $short = false): HtmlString
    {
        if (! $at) {
            return new HtmlString('');
        }
        $at = FastDate::instance($at);
        $clock = '<span class="when-t">'.$at->format('H:i').'</span>';
        $day = e(self::at($at, words: $words));
        $text = match (true) {
            $short && $at->isToday() && ! $time => $clock,
            $time => $day.' '.$clock,
            default => $day,
        };

        return new HtmlString('<time class="when" datetime="'.$at->toIso8601String().'" title="'.$at->format('d.m.Y H:i').'">'.$text.'</time>');
    }

    /** Строка чата и ленты: сегодня — время, остальные дни — дата; с временем — дата и время. Текстом. */
    public static function stamp(?\DateTimeInterface $at, bool $time = false): string
    {
        if (! $at) {
            return '';
        }

        return FastDate::instance($at)->isToday() && ! $time ? FastDate::instance($at)->format('H:i') : self::at($at, $time);
    }

    /** Дата в ячейке таблицы — `at` («сегодня 16:45», «28.10 11:27»). */
    public static function cell(?\DateTimeInterface $at, bool $time = false): string
    {
        return self::at($at, $time);
    }

    /** День во фразе — `at` без «сегодня» и «вчера»: «с 06.10», «до 21.10 23:59». */
    public static function day(?\DateTimeInterface $at, bool $time = false): string
    {
        return self::at($at, $time, words: false);
    }

    public static function time(Carbon $at, string $class = ''): string
    {
        return '<time datetime="'.$at->toIso8601String().'" title="'.$at->format('d.m.Y H:i').'" class="nums '.$class.'">'.self::short($at).'</time>';
    }
}
