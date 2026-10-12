<?php

namespace App\Offers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Слоты публикации (владелец 03.10.2026): предложения выходят менеджерам пачкой каждый день в 16:00 МСК, приём
 * подтверждений закрывается через три дня в 17:00. Время приложения — московское, отдельной зоны здесь не нужно.
 * Одна дверь для «когда выйдет» и «до какого принимаем» — и для кнопок CRM, и для часов, и для маршрута.
 */
final class Slots
{
    public const NOW = 'now';

    public const NEAREST = 'nearest';

    public const NEXT = 'next';

    /** «Срочно» (12.10.2026): сейчас, всем сразу, громко, со своим сроком приёма (`urgentUntil`). */
    public const URGENT = 'urgent';

    public const WHEN = [self::NOW, self::NEAREST, self::NEXT, self::URGENT];

    /** Срок приёма срочного: из поля «Приём до», не раньше чем через 30 минут; пусто — через 3 часа, по 5 минут. */
    public static function urgentUntil(?string $input): Carbon
    {
        $at = null;
        try {
            $at = filled($input) ? Carbon::parse($input) : null;
        } catch (\Throwable) {
        }
        $at ??= self::urgentDefault();

        return $at->max(now()->addMinutes(30))->second(0);
    }

    /** Подставленный срок срочного: через 3 часа, вверх до 5 минут. */
    public static function urgentDefault(): Carbon
    {
        $at = now()->addHours(3)->second(0);

        return $at->minute((int) ceil($at->minute / 5) * 5);
    }

    /** Ближайший слот строго после этой минуты: до 16:00 — сегодня, с 16:00 — завтра. */
    public static function nearest(?Carbon $from = null): Carbon
    {
        $from ??= now();
        $slot = $from->copy()->setTime(self::hour(), 0);

        return $slot->greaterThan($from) ? $slot : $slot->addDay();
    }

    /** Следующий за ближайшим. */
    public static function next(?Carbon $from = null): Carbon
    {
        return self::nearest($from)->addDay();
    }

    /** Время выхода по выбору в CRM; «сейчас» — null. */
    public static function at(string $when, ?Carbon $from = null): ?Carbon
    {
        return match ($when) {
            self::NEAREST => self::nearest($from),
            self::NEXT => self::next($from),
            default => null,
        };
    }

    /** Срок приёма по умолчанию: через `days` дней после выхода, в 17:00. */
    public static function closeFor(Carbon $published, ?int $days = null): Carbon
    {
        return $published->copy()->startOfDay()->addDays($days ?? (int) config('xcar.bids_window_days'))->setTime((int) config('xcar.bids_close_hour'), 0);
    }

    /** Конец показа в галерее: через `gallery_days` дней после входа, в 23:59 (владелец 08.10.2026). */
    public static function galleryUntil(Carbon $from): Carbon
    {
        return $from->copy()->startOfDay()->addDays((int) config('xcar.gallery_days'))->setTime(23, 59);
    }

    /** «Сегодня, 16:00», «Завтра, 16:00», «Пн, 6 окт, 16:00». */
    public static function label(Carbon $at): string
    {
        $time = $at->format('H:i');
        if ($at->isToday()) {
            return 'Сегодня, '.$time;
        }
        if ($at->isTomorrow()) {
            return 'Завтра, '.$time;
        }

        return Str::ucfirst($at->isoFormat('dd')).', '.$at->translatedFormat('j M').', '.$time;
    }

    /** Коротко, для переключателя в карточке строки: «Сегодня 16:00», «Завтра 16:00», «Пн 16:00». */
    public static function short(Carbon $at): string
    {
        $day = $at->isToday() ? 'Сегодня' : ($at->isTomorrow() ? 'Завтра' : Str::ucfirst($at->isoFormat('dd')));

        return $day.' '.$at->format('H:i');
    }

    /** То же строчными — внутри фразы: «Выйдет сегодня в 16:00». */
    public static function phrase(Carbon $at): string
    {
        $time = 'в '.$at->format('H:i');
        if ($at->isToday()) {
            return 'сегодня '.$time;
        }
        if ($at->isTomorrow()) {
            return 'завтра '.$time;
        }

        return $at->translatedFormat('j M').' '.$time;
    }

    /**
     * Три варианта для выбора в CRM, по умолчанию — ближайший слот.
     *
     * @return list<array{when: string, title: string, at: ?Carbon, label: ?string}>
     */
    public static function choices(): array
    {
        $nearest = self::nearest();
        $next = self::next();

        return [
            ['when' => self::NOW, 'title' => 'Сейчас', 'at' => null, 'label' => null],
            ['when' => self::NEAREST, 'title' => 'В ближайший слот', 'at' => $nearest, 'label' => self::label($nearest)],
            ['when' => self::NEXT, 'title' => 'В следующий слот', 'at' => $next, 'label' => self::label($next)],
        ];
    }

    private static function hour(): int
    {
        return (int) config('xcar.slot_hour');
    }
}
