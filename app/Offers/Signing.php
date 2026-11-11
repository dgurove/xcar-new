<?php

namespace App\Offers;

use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Запись на подписание у поставщика — как бронь (11.11.2026, Альфа; владелец: «запись на встречу должна быть красиво
 * оформлена, а не тупо текстом»): мы вносим свободное время и адрес филиала (`deals.signing_slots|address`, исход «Поставщик
 * прислал время» — шторкой `x-deal.signing-slots-sheet`), менеджер выбирает плиткой (`deals.signing_at`, исход «Время
 * согласовано»), дальше карточка встречи `x-deal.appointment` с «В календарь» и «Маршрут».
 */
final class Signing
{
    public const SLOTS_EXIT = 'поставщик прислал время';

    public const PICK_EXIT = 'время согласовано';

    /** Исход «Поставщик прислал время»: только со слотами — шторкой, голым нажатием нельзя. */
    public static function slotsExit(?Outcome $exit): bool
    {
        return $exit !== null && $exit->actor === Actor::Staff && mb_strtolower(trim($exit->label)) === self::SLOTS_EXIT;
    }

    /** Исход менеджера «Время согласовано»: только выбранным временем (`PickSigningTime`). */
    public static function pickExit(?Outcome $exit): bool
    {
        return $exit !== null && $exit->actor === Actor::Manager && mb_strtolower(trim($exit->label)) === self::PICK_EXIT;
    }

    /** Этап, где менеджер выбирает время. */
    public static function picks(?Stage $stage): bool
    {
        return (bool) $stage?->loadedExits()->contains(fn (Outcome $e) => self::pickExit($e));
    }

    /** @return Collection<int, Carbon> свободное время поставщика, ещё не прошедшее, по порядку */
    public static function slots(Deal $deal): Collection
    {
        return collect($deal->signing_slots ?? [])->map(fn ($s) => Carbon::parse($s))->filter(fn (Carbon $c) => $c->isFuture())->sort()->values();
    }

    /** Адрес филиала, что вписали в прошлый раз у этого вендора, — по умолчанию в шторке слотов. */
    public static function lastAddress(Deal $deal): ?string
    {
        return Deal::whereNotNull('signing_address')->where('id', '!=', $deal->id)
            ->whereHas('offer', fn ($q) => $q->where('vendor_id', $deal->offer?->vendor_id))->latest('updated_at')->value('signing_address');
    }

    /** Карточка встречи: время выбрано и сделка идёт. */
    public static function booked(?Deal $deal): bool
    {
        return $deal?->signing_at !== null && $deal->isActive();
    }

    /** Файл календаря: встреча на час. */
    public static function ics(Deal $deal): string
    {
        $at = $deal->signing_at->copy()->utc();
        $esc = fn (string $s) => str_replace(["\\", ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $s);
        $title = 'Подписание договора: '.$deal->offer->titleWithYear();

        return implode("\r\n", array_filter([
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//XCar//Signing//RU', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:signing-'.$deal->id.'-'.$at->format('YmdHi').'@xcar.ru',
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$at->format('Ymd\THis\Z'),
            'DTEND:'.$at->copy()->addHour()->format('Ymd\THis\Z'),
            'SUMMARY:'.$esc($title),
            $deal->signing_address ? 'LOCATION:'.$esc($deal->signing_address) : null,
            'END:VEVENT', 'END:VCALENDAR', '',
        ]));
    }
}
