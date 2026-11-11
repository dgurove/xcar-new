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
    // Альфа на «запишите на …» отвечает «свободно» или своим временем (11.11.2026): её время — слотами, менеджер выбирает снова.
    public const SLOTS_EXIT = 'поставщик предложил другое время';

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
     * Этап, на котором заранее спрашиваем менеджера, когда покупатель сможет подписать (11.11.2026, владелец: «спрашивать,
     * когда Альфа пишет, что договор начали готовить»): «Подготовка договора» — её исход ведёт на выбор времени.
     */
    public static function asksTime(?Stage $stage): bool
    {
        // «Запись на подписание» тоже ведёт на выбор («Поставщик предложил другое время») — но там уже пишем, не спрашиваем.
        return $stage !== null && ! self::awaitsSlots($stage)
            && $stage->loadedExits()->contains(fn (Outcome $e) => $e->to?->loadedExits()->contains(fn (Outcome $x) => self::pickExit($x)));
    }

    /** Этап, где ждём ответа поставщика на запись: у него исход «Поставщик предложил другое время». */
    public static function awaitsSlots(?Stage $stage): bool
    {
        return (bool) $stage?->loadedExits()->contains(fn (Outcome $e) => self::slotsExit($e));
    }

    public const CONFIRM_EXIT = 'запись подтверждена';

    /** Исход «Запись подтверждена»: только с временем, что подтвердил поставщик, — шторкой (`ConfirmSigning`). */
    public static function confirmExit(?Outcome $exit): bool
    {
        return $exit !== null && $exit->actor === Actor::Staff && mb_strtolower(trim($exit->label)) === self::CONFIRM_EXIT;
    }

    /**
     * Когда покупатель может подписать (`signing_wish`, 11.11.2026, владелец: «сначала дни — можно несколько, потом время —
     * можно несколько»): ещё не прошедшие дни и время по порядку. Пусто — не названо.
     *
     * @return array{days: Collection<int, Carbon>, times: list<string>}|null
     */
    public static function wish(Deal $deal): ?array
    {
        $w = $deal->signing_wish ?? [];
        $times = array_values(array_filter((array) ($w['times'] ?? []), fn ($t) => preg_match('/^\d{1,2}:\d{2}$/', (string) $t)));
        usort($times, fn ($a, $b) => strcmp(sprintf('%05s', $a), sprintf('%05s', $b)));
        $days = collect($w['days'] ?? [])->map(fn ($d) => Carbon::parse($d, 'Europe/Moscow')->startOfDay())
            ->filter(fn (Carbon $d) => $d->copy()->endOfDay()->isFuture())->sort()->values();

        return $days->isNotEmpty() && $times !== [] ? ['days' => $days, 'times' => $times] : null;
    }

    /** Дело «Когда покупатель сможет подписать?»: спросили заранее, не ответил и время ещё не выбрано. */
    public static function wishDue(Deal $deal): bool
    {
        return $deal->signing_asked_at !== null && self::wish($deal) === null && $deal->signing_at === null && $deal->isActive();
    }

    /** Все рабочие часы филиала отмечены — «в любое время». */
    public static function anyTime(Deal $deal, array $times): bool
    {
        return array_diff(self::times($deal), $times) === [];
    }

    /** «чт 12.11 или пт 13.11, 11:00 или 15:00» — строкой шага и чата; null — не названо. */
    public static function wishLine(Deal $deal): ?string
    {
        if (! ($w = self::wish($deal))) {
            return null;
        }
        $days = self::either($w['days']->map(fn (Carbon $d) => $d->locale('ru')->isoFormat('dd').' '.$d->format('d.m'))->all());

        return $days.', '.(self::anyTime($deal, $w['times']) ? 'весь день' : self::either($w['times']));
    }

    /** В письме поставщику: «в четверг 12.11 или в пятницу 13.11, в 11:00 или 15:00»; весь день — «в любое время с 9:00 до 18:00». */
    public static function wishWords(Deal $deal): string
    {
        if (! ($w = self::wish($deal))) {
            return '';
        }
        $days = self::either($w['days']->map(fn (Carbon $d) => self::on($d).' '.$d->format('d.m'))->all());
        $o = self::office($deal);
        $hm = fn (string $t) => preg_replace('/^0(\d)/', '$1', $t);

        return $days.', '.(self::anyTime($deal, $w['times']) ? 'в любое время с '.$hm($o['from']).' до '.$hm($o['to']) : 'в '.self::either($w['times']));
    }

    /** «a», «a или b», «a, b или c». @param list<string> $items */
    private static function either(array $items): string
    {
        return count($items) > 1 ? implode(', ', array_slice($items, 0, -1)).' или '.end($items) : (string) ($items[0] ?? '');
    }

    /** «в четверг», «во вторник», «в среду». */
    private static function on(Carbon $c): string
    {
        return match ($c->dayOfWeekIso) {
            1 => 'в понедельник', 2 => 'во вторник', 3 => 'в среду', 4 => 'в четверг', 5 => 'в пятницу', 6 => 'в субботу', default => 'в воскресенье',
        };
    }

    /** «чт 12.11 в 15:00» — строкой шага и чата. */
    public static function short(Carbon $at): string
    {
        $m = $at->copy()->timezone('Europe/Moscow');

        return $m->locale('ru')->isoFormat('dd').' '.$m->format('d.m').' в '.$m->format('G:i');
    }

    /** «в четверг 12.11 в 15:00» — в письме поставщику (`{{ signing_when }}`). */
    public static function words(Carbon $at): string
    {
        $m = $at->copy()->timezone('Europe/Moscow');

        return self::on($m).' '.$m->format('d.m').' в '.$m->format('G:i');
    }

    /** @return list<string> «9:00» … «17:00» — по часу в часах филиала (последний — за час до закрытия) */
    public static function times(Deal $deal): array
    {
        $office = self::office($deal);
        $from = (int) substr($office['from'], 0, 2) + (substr($office['from'], 3, 2) !== '00' ? 1 : 0);
        $to = (int) substr($office['to'], 0, 2) - 1;

        return array_map(fn (int $h) => sprintf('%d:00', $h), $to >= $from ? range($from, $to) : range(9, 17));
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
