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

    /**
     * Этап, на котором спрашиваем менеджера, когда покупатель сможет подписать (11.11.2026, владелец: «спрашивать, когда Альфа
     * пишет, что договор начали готовить»): «Подготовка договора» — её исход ведёт на этап, где поставщик присылает время.
     */
    public static function asksDays(?Stage $stage): bool
    {
        return (bool) $stage?->loadedExits()->contains(fn (Outcome $e) => $e->to?->loadedExits()->contains(fn (Outcome $x) => self::slotsExit($x)));
    }

    /** Этап, где ждём свободное время от поставщика. */
    public static function awaitsSlots(?Stage $stage): bool
    {
        return (bool) $stage?->loadedExits()->contains(fn (Outcome $e) => self::slotsExit($e));
    }

    /** Дело «Когда покупатель сможет подписать?»: спросили, не ответил и время ещё не выбрано. */
    public static function wishDue(Deal $deal): bool
    {
        return $deal->signing_asked_at !== null && empty($deal->signing_wish['days']) && $deal->signing_at === null && $deal->isActive();
    }

    public const PARTS = ['any' => 'Любое', 'morning' => 'До обеда', 'afternoon' => 'После обеда'];

    /** @return Collection<int, Carbon> дни покупателя, ещё не прошедшие */
    public static function wishDays(Deal $deal): Collection
    {
        return collect($deal->signing_wish['days'] ?? [])->map(fn ($d) => Carbon::parse($d, 'Europe/Moscow'))
            ->filter(fn (Carbon $c) => $c->copy()->endOfDay()->isFuture())->sort()->values();
    }

    /** «четверг 12.11 или пятница 13.11, после обеда» — строкой шага и письма; null — дней нет. */
    public static function wishLine(Deal $deal, bool $long = false): ?string
    {
        $days = self::wishDays($deal);
        if ($days->isEmpty()) {
            return null;
        }
        $names = $days->map(fn (Carbon $c) => ($long ? $c->locale('ru')->isoFormat('dddd') : $c->locale('ru')->isoFormat('dd')).' '.$c->format('d.m'));
        $line = $names->count() > 1 ? $names->slice(0, -1)->implode(', ').' или '.$names->last() : $names->first();
        $part = $deal->signing_wish['part'] ?? 'any';

        return $line.($part !== 'any' ? ', '.mb_strtolower(self::PARTS[$part]) : '');
    }

    /** Фраза письма поставщику: «Покупатель может подъехать в четверг 12.11 или в пятницу 13.11, после обеда.» */
    public static function letterLine(Deal $deal): string
    {
        $days = self::wishDays($deal);
        if ($days->isEmpty()) {
            return '';
        }
        $names = $days->map(fn (Carbon $c) => self::on($c).' '.$c->format('d.m'));
        $line = $names->count() > 1 ? $names->slice(0, -1)->implode(', ').' или '.$names->last() : $names->first();
        $part = $deal->signing_wish['part'] ?? 'any';

        return 'Покупатель может подъехать '.$line.($part !== 'any' ? ', '.mb_strtolower(self::PARTS[$part]) : '').'.';
    }

    /** «в четверг», «во вторник», «в среду». */
    private static function on(Carbon $c): string
    {
        $name = $c->locale('ru')->isoFormat('dddd');

        return ($c->dayOfWeekIso === 2 ? 'во ' : 'в ').match ($c->dayOfWeekIso) { 3 => 'среду', 5 => 'пятницу', 6 => 'субботу', default => $name };
    }

    /**
     * Филиал вендора для подписания (`vendors.signing_office`): адрес, рабочие дни (ISO, 1 — пн), часы, «по записи», контакты.
     *
     * @return array{address: ?string, days: list<int>, from: string, to: string, by_appointment: bool, contacts: list<array{name: string, phone: ?string, ext: ?string}>}
     */
    public static function office(?Deal $deal): array
    {
        $o = $deal?->offer?->vendor?->signing_office ?? [];

        return [
            'address' => ($o['address'] ?? null) ?: null,
            'days' => array_values(array_map('intval', $o['days'] ?? [1, 2, 3, 4, 5])) ?: [1, 2, 3, 4, 5],
            'from' => $o['from'] ?? '09:00',
            'to' => $o['to'] ?? '18:00',
            'by_appointment' => (bool) ($o['by_appointment'] ?? false),
            'contacts' => array_values($o['contacts'] ?? []),
        ];
    }

    /** Адрес по умолчанию в шторке слотов: у сделки → филиал вендора → прошлый у вендора. */
    public static function defaultAddress(Deal $deal): ?string
    {
        return $deal->signing_address ?? self::office($deal)['address'] ?? self::lastAddress($deal);
    }

    /** @return Collection<int, Carbon> ближайшие рабочие дни филиала с завтра — плитки выбора дней */
    public static function workdays(Deal $deal, int $count = 6): Collection
    {
        // С завтра: договор ещё готовят, сегодня подписать не выйдет.
        $office = self::office($deal);
        $day = now('Europe/Moscow')->startOfDay()->addDay();
        $out = collect();
        for ($i = 0; $out->count() < $count && $i < 21; $i++, $day->addDay()) {
            if (in_array($day->dayOfWeekIso, $office['days'], true)) {
                $out->push($day->copy());
            }
        }

        return $out;
    }

    /** Время вне часов филиала — словами, иначе null. */
    public static function outsideHours(Deal $deal, Carbon $at): ?string
    {
        $office = self::office($deal);
        $m = $at->copy()->timezone('Europe/Moscow');
        if (! in_array($m->dayOfWeekIso, $office['days'], true)) {
            return $m->format('d.m').' филиал не работает';
        }
        if ($m->format('H:i') < $office['from'] || $m->format('H:i') >= $office['to']) {
            return $m->format('d.m H:i').' вне часов филиала, '.$office['from'].'–'.$office['to'];
        }

        return null;
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
