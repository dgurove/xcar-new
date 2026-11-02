<?php

namespace App\Park;

use App\Mail\Direction;
use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Mail\Thread;
use Illuminate\Support\Carbon;

/**
 * Подсказки дня приёма — чипами над календарём даты приёма (`x-park.accepted`, 02.11.2026, владелец: «многие даты
 * приёма неверные, менять безумно неудобно»): приём отмечают в день ввода, а не в день, когда машина приехала. Настоящий
 * день — в письмах: письмо-заявка вендора («просьба организовать приём») и наш фотоотчёт о принятом ТС (уходит в тот же
 * день или через пару дней). Даты съёмки у кадров приёма нет — сжатие стирает EXIF.
 */
final class AcceptHints
{
    /** Заезд — письма не раньше месяца до нынешней даты приёма: прошлый заезд той же машины подсказкой не встаёт. */
    private const VISIT_DAYS = 30;

    /** Ключ памяти запроса: таблица «Наличия» считает факты всех строк двумя запросами (`prime`), а не по три на строку. */
    private const MEMO = 'park.accept-hints';

    /**
     * Варианты по порядку времени, без повторов дня: «Заявка 02.09», «Отчёт 06.09», «Заявка в системе 07.10».
     *
     * @return list<array{label: string, at: Carbon, source: string}>
     */
    public static function for(Vehicle $vehicle): array
    {
        $facts = self::factsOf($vehicle);
        $report = self::report($vehicle);
        $asked = self::asked($vehicle, $report ?? $vehicle->accepted_at?->copy()->endOfDay() ?? now());
        $till = $vehicle->accepted_at?->copy()->endOfDay();
        $system = collect($facts['requests'])->filter(fn (Carbon $at) => ! $till || $at->lte($till))->max();
        $hints = [];
        foreach (['request' => [$asked, 'Заявка'], 'report' => [$report, 'Отчёт'], 'system' => [$system, 'Заявка в системе']] as $source => [$at, $word]) {
            if (! $at || isset($hints[$day = $at->toDateString()])) {
                continue;
            }
            $hints[$day] = ['label' => $word.' '.$at->format($at->isCurrentYear() ? 'd.m' : 'd.m.Y'), 'at' => $at->copy()->setTime(12, 0), 'source' => $source];
        }
        ksort($hints);

        return array_values($hints);
    }

    /** Последнее письмо-заявка вендора на приём перед `$before` — когда машину просили принять. */
    public static function asked(Vehicle $vehicle, Carbon $before): ?Carbon
    {
        return collect(self::factsOf($vehicle)['letters'])
            ->filter(fn ($l) => $l['in'] && $l['intent'] === Intent::Intake->value && $l['at']->lt($before))->pluck('at')->max();
    }

    /** Наше первое письмо-фотоотчёт этого заезда: машина к нему уже стояла. */
    public static function report(Vehicle $vehicle): ?Carbon
    {
        $from = $vehicle->accepted_at?->copy()->subDays(self::VISIT_DAYS);

        return collect(self::factsOf($vehicle)['letters'])
            ->filter(fn ($l) => ! $l['in'] && $l['intent'] === Intent::Accepted->value && (! $from || $l['at']->gte($from)))->pluck('at')->min();
    }

    /** Факты строк списка разом — до отрисовки таблицы. */
    public static function prime(iterable $vehicles): void
    {
        $ids = collect($vehicles)->pluck('id')->all();
        if ($ids && ! app()->runningInConsole()) {
            request()->attributes->set(self::MEMO, self::facts($ids) + (request()->attributes->get(self::MEMO) ?? []));
        }
    }

    /** @return array{letters: list<array{in: bool, intent: string, at: Carbon}>, requests: list<Carbon>} */
    private static function factsOf(Vehicle $vehicle): array
    {
        return (app()->runningInConsole() ? null : request()->attributes->get(self::MEMO)[$vehicle->id] ?? null) ?? self::facts([$vehicle->id])[$vehicle->id];
    }

    /** Письма-заявки и наши отчёты в ветках ящиков парковки и заявки на приём — по ТС. */
    private static function facts(array $ids): array
    {
        $out = array_fill_keys($ids, ['letters' => [], 'requests' => []]);
        $letters = Message::query()->join('mail_threads as t', 't.id', '=', 'mail_messages.thread_id')
            ->whereIn('t.id', Thread::park()->whereIn('vehicle_id', $ids)->select('id'))
            ->whereIn('mail_messages.intent', [Intent::Intake->value, Intent::Accepted->value])
            ->get(['t.vehicle_id', 'mail_messages.direction', 'mail_messages.intent', 'mail_messages.date_at']);
        foreach ($letters as $m) {
            if ($m->date_at) {
                $out[$m->vehicle_id]['letters'][] = ['in' => $m->direction === Direction::In, 'intent' => $m->intent, 'at' => $m->date_at];
            }
        }
        foreach (Request::whereIn('vehicle_id', $ids)->where('type', RequestType::Intake)->get(['vehicle_id', 'created_at']) as $r) {
            $out[$r->vehicle_id]['requests'][] = $r->created_at;
        }

        return $out;
    }
}
