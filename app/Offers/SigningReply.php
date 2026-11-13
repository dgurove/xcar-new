<?php

namespace App\Offers;

use App\Mail\Direction;
use App\Mail\Message;
use Illuminate\Support\Carbon;

/**
 * Ответ поставщика на «Запись на подписание» (13.11.2026, Альфа по BMW X6 № 2610041185: «Никто не просил запись. На сегодня не
 * записан» → «Есть на 13.11.2026 11,12,13. На какое записать?» → наше «13:00» → «записали» — ни одно письмо не разобрали, шаг
 * так и стоял). Что сказал поставщик последним письмом с входа в шаг:
 * — `booked` «записали» / «записан на …» / «запись подтверждена»: время — из письма, иначе из нашего ответа перед ним («13:00»)
 *   и дня из его предложения («13.11») — кнопка «Запись подтверждена» с этим временем (`x-deal.signing-confirm-sheet`);
 * — `slots` «есть на 13.11 11, 12, 13» — время для шторки «Поставщик предложил другое время» (`x-deal.signing-slots-sheet`);
 * — `none` «никто не просил запись», «не записан» — ход наш, написать снова.
 * Шаг письмо само не двигает: решает сотрудник одним нажатием, как с любым письмом поставщика.
 */
final class SigningReply
{
    /** @param  list<Carbon>  $slots */
    private function __construct(public readonly string $kind, public readonly Message $message, public readonly ?Carbon $at = null, public readonly array $slots = []) {}

    public static function of(Deal $deal): ?self
    {
        $position = $deal->offer?->position();
        if (! $position || ! Signing::confirmable($position->stage)) {
            return null;
        }
        $letters = Message::whereHas('thread', fn ($t) => $t->where('offer_id', $deal->offer_id))
            ->where('date_at', '>=', $position->entered_at?->copy()->subDay() ?? now()->subWeek())
            ->orderBy('date_at')->get();
        $theirs = $letters->filter(fn (Message $m) => $m->direction === Direction::In && $m->date_at >= $position->entered_at);
        $last = $theirs->last();
        if (! $last) {
            return null;
        }
        $text = mb_strtolower($last->replyText());
        $base = $last->date_at ?? now();
        if (preg_match('/никто\s+не\s+просил|не\s+записан|записи\s+нет|не\s+смогли\s+записать/u', $text)) {
            return new self('none', $last);
        }
        if (preg_match('/запис(?:али|ал|ан[аы]?|ь\s+подтвержд)/u', $text)) {
            $at = self::when($text, $base);
            if (! $at) {
                // «записали» без времени — на то, что мы назвали последним ответом, в день из его предложения.
                $ours = $letters->filter(fn (Message $m) => $m->direction === Direction::Out && $m->date_at < $last->date_at)->last();
                $offer = $theirs->filter(fn (Message $m) => $m->date_at < $last->date_at)->map(fn (Message $m) => self::offered(mb_strtolower($m->replyText()), $m->date_at))->filter()->last();
                $at = $ours ? self::when(mb_strtolower($ours->replyText()), $base, $offer[0] ?? null) : null;
            }

            return new self('booked', $last, $at);
        }
        if ($slots = self::offered($text, $base)) {
            return new self('slots', $last, null, $slots);
        }

        return null;
    }

    /** Время в тексте: «13.11 в 13:00», «13.11.2026 13:00», «на 13:00» (день — `$day` или из письма). */
    public static function when(string $text, Carbon $base, ?Carbon $day = null): ?Carbon
    {
        $date = self::date($text, $base) ?? $day;
        // Время — только через двоеточие: «13.11» — это день, а не 13:11.
        if (! preg_match('/(?<![\d.])([01]?\d|2[0-3]):([0-5]\d)(?![\d.])/u', $text, $t)) {
            return null;
        }
        $date ??= $base->copy()->timezone('Europe/Moscow');

        return Carbon::create($date->year, $date->month, $date->day, (int) $t[1], (int) $t[2], 0, 'Europe/Moscow');
    }

    /** @return list<Carbon> «есть на 13.11.2026 11,12,13» — день и часы */
    public static function offered(string $text, Carbon $base): array
    {
        if (! preg_match('/(?:есть|свободн\w*)\s+(?:на\s+)?(\d{1,2}\.\d{1,2}(?:\.\d{2,4})?)\s*(?:в\s+)?((?:\d{1,2}(?:[:.]\d{2})?\s*(?:,|и|\s)\s*)*\d{1,2}(?:[:.]\d{2})?)/u', $text, $m)) {
            return [];
        }
        $day = self::date($m[1], $base);
        if (! $day) {
            return [];
        }
        preg_match_all('/(\d{1,2})(?:[:.](\d{2}))?/u', $m[2], $h, PREG_SET_ORDER);

        return array_values(array_filter(array_map(fn ($x) => (int) $x[1] >= 7 && (int) $x[1] <= 21
            ? Carbon::create($day->year, $day->month, $day->day, (int) $x[1], (int) ($x[2] ?? 0), 0, 'Europe/Moscow') : null, $h)));
    }

    /** «13.11» / «13.11.2026» → день (год — ближайший вперёд от письма). */
    private static function date(string $text, Carbon $base): ?Carbon
    {
        if (! preg_match('/(?<!\d)(\d{1,2})\.(\d{1,2})(?:\.(\d{2,4}))?(?![\d:])/u', $text, $d) || (int) $d[2] < 1 || (int) $d[2] > 12 || (int) $d[1] < 1 || (int) $d[1] > 31) {
            return null;
        }
        $year = isset($d[3]) ? ((int) $d[3] < 100 ? 2000 + (int) $d[3] : (int) $d[3]) : (int) $base->copy()->timezone('Europe/Moscow')->year;

        return Carbon::create($year, (int) $d[2], (int) $d[1], 0, 0, 0, 'Europe/Moscow');
    }

    /** Строка в шаге: «Записали на пт 13.11 в 13:00» (кто — понятно из шага: ждём поставщика). */
    public function line(): string
    {
        return match ($this->kind) {
            'booked' => $this->at ? 'Записали на '.Signing::short($this->at) : 'Записали, время не названо',
            'slots' => 'Предлагают '.$this->slots[0]->format('d.m').': '.implode(', ', array_map(fn (Carbon $s) => $s->format('G:i'), $this->slots)),
            default => 'Не записали',
        };
    }
}
