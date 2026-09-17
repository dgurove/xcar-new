<?php

namespace App\Chats;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Рабочее время площадки по Москве, одно для всех: от него — автоответ администрации и
 * статус менеджера и площадки в чате покупателя. Реального seen_at покупателю не показываем:
 * в рабочее время «в сети», после — «был в сети N ч N мин назад» от закрытия.
 */
final class Hours
{
    public const OPEN = 9;

    public const CLOSE = 21;

    public const TZ = 'Europe/Moscow';

    public static function open(?CarbonInterface $at = null): bool
    {
        $hour = (int) CarbonImmutable::instance($at ?? now())->setTimezone(self::TZ)->format('G');

        return $hour >= self::OPEN && $hour < self::CLOSE;
    }

    /** «в сети» или «был в сети 2 ч 15 мин назад»: от последних 21:00 (до утра — вчерашних). */
    public static function presence(bool $feminine = false, ?CarbonInterface $at = null): string
    {
        $now = CarbonImmutable::instance($at ?? now())->setTimezone(self::TZ);
        if (self::open($now)) {
            return 'в сети';
        }
        $closed = $now->setTime(self::CLOSE, 0);
        if ($closed->gt($now)) {
            $closed = $closed->subDay();
        }
        $minutes = (int) $closed->diffInMinutes($now);
        $parts = array_filter([
            intdiv($minutes, 60) ? intdiv($minutes, 60).' ч' : null,
            $minutes % 60 ? ($minutes % 60).' мин' : null,
        ]);

        return ($feminine ? 'была' : 'был').' в сети '.($parts ? implode(' ', $parts).' назад' : 'только что');
    }
}
