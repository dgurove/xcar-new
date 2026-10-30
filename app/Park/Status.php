<?php

namespace App\Park;

use App\Support\Ago;

/**
 * Что сейчас с ТС — столбец «Статус» в «Наличии». Одна строка словами; `act` — нужно наше действие (кружок перед
 * словами, красный — просрочено), иначе мы просто ждём (одна надпись). Ошибки данных (VIN, нет тарифа) — не статус,
 * они у названия (`Alerts`). Берёт то, что уже загружено списком: заявки, число ждущих ответа веток (`waiting_count`).
 */
final class Status
{
    /** @return array{label: string, act: bool, late: bool} */
    public static function of(Vehicle $v): array
    {
        $say = fn (string $label, bool $act = false, bool $late = false) => ['label' => $label, 'act' => $act, 'late' => $late];
        if ($v->state !== VehicleState::Stored) {
            return $say(mb_strtolower($v->state->label()).($v->state === VehicleState::Released && $v->released_at ? ' '.Ago::day($v->released_at) : ''));
        }
        // Висящие задачи (`Tasks`, список кладёт их атрибутом `task_keys`): первая — словом, остальные числом.
        $tasks = $v->getAttribute('task_keys');
        if ($tasks) {
            return $say(mb_strtolower(Tasks::LABELS[$tasks[0]]).(count($tasks) > 1 ? ' +'.(count($tasks) - 1) : ''), true);
        }
        if (is_array($tasks)) {
            return $say($v->sold_at ? 'на выдачу, ждём покупателя' : 'на парковке');
        }
        if ($v->waiting_count ?? 0) {
            return $say('ответить на письмо', true);
        }
        $open = $v->requests->filter(fn (Request $r) => $r->isOpen());
        if ($open->contains('type', RequestType::Release)) {
            return $say('выдать', true);
        }
        if ($v->sold_at) {
            return $say('на выдачу, ждём покупателя');
        }
        if (! $v->yard_id) {
            return $say('указать парковку', true);
        }

        return $say('на парковке');
    }
}
