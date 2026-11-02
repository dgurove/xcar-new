<?php

namespace App\Park;

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

    /**
     * Варианты по порядку времени, без повторов дня: «Заявка 02.09», «Отчёт 06.09», «Заявка в системе 07.10».
     *
     * @return list<array{label: string, at: Carbon, source: string}>
     */
    public static function for(Vehicle $vehicle): array
    {
        $report = self::report($vehicle);
        $asked = self::asked($vehicle, $report ?? $vehicle->accepted_at?->copy()->endOfDay() ?? now());
        $system = Request::where('vehicle_id', $vehicle->id)->where('type', RequestType::Intake)
            ->when($vehicle->accepted_at, fn ($q, $at) => $q->where('created_at', '<=', $at->copy()->endOfDay()))->max('created_at');
        $hints = [];
        foreach (['request' => [$asked, 'Заявка'], 'report' => [$report, 'Отчёт'], 'system' => [$system ? Carbon::parse($system) : null, 'Заявка в системе']] as $source => [$at, $word]) {
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
        $at = self::letters($vehicle)->where('direction', 'in')->where('intent', Intent::Intake->value)->where('date_at', '<', $before)->max('date_at');

        return $at ? Carbon::parse($at) : null;
    }

    /** Наше первое письмо-фотоотчёт этого заезда: машина к нему уже стояла. */
    public static function report(Vehicle $vehicle): ?Carbon
    {
        $at = self::letters($vehicle)->where('direction', 'out')->where('intent', Intent::Accepted->value)
            ->when($vehicle->accepted_at, fn ($q, $at) => $q->where('date_at', '>=', $at->copy()->subDays(self::VISIT_DAYS)))->min('date_at');

        return $at ? Carbon::parse($at) : null;
    }

    private static function letters(Vehicle $vehicle)
    {
        return Message::whereIn('thread_id', Thread::park()->where('vehicle_id', $vehicle->id)->select('id'));
    }
}
