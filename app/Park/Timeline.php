<?php

namespace App\Park;

use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Таймлайн дела: Письмо → Звонок → Эвакуация → Приём → Отчёт вендору → Хранение → Выдача → Отчёт вендору.
 * Каждый шаг решает своё состояние сам; текущий — последний из «своих текущих», остальные такие — todo
 * (действие есть, но форма не раскрыта). Подсказки — одной строкой, без точек.
 */
final class Timeline
{
    /** @return list<Step> */
    public static function for(Vehicle $v, ?Request $open, bool $hasLetters, Collection $events, bool $callAgain = false): array
    {
        $steps = [];
        $ir = $open && in_array($open->type, [RequestType::Intake, RequestType::Tow], true) ? $open
            : $v->requests->first(fn (Request $r) => in_array($r->type, [RequestType::Intake, RequestType::Tow], true) && $r->state === RequestState::Done);
        $release = $open?->type === RequestType::Release ? $open : $v->requests->first(fn (Request $r) => $r->type === RequestType::Release && $r->state === RequestState::Done);
        $billable = $v->vendor?->kind->billable() ?? false;
        $sent = fn (string $what) => $events->contains(fn ($e) => $e->type === EventType::ReportSent && ($e->payload['what'] ?? null) === $what);

        $steps[] = new Step('letter', $hasLetters ? 'Письмо' : 'Заведена руками', Step::DONE, at: $v->created_at);

        if ($ir && ($ir->needsCall() || $ir->contacted_at || $ir->next_call_at || ($callAgain && $ir->isOpen()))) {
            $current = $ir->isOpen() && ($ir->needsCall() || $callAgain);
            $chips = array_values(array_filter([
                $ir->delivery?->label(),
                $ir->next_call_at && ! $ir->contacted_at ? 'перезвонить '.$ir->next_call_at->translatedFormat('j M, H:i') : null,
            ]));
            $steps[] = new Step('call', 'Звонок', $current ? Step::CURRENT : Step::DONE, $current ? 'Позвонить страхователю, узнать кто и когда привезёт ТС' : null, $ir->contacted_at, $chips,
                $current ? ['kind' => 'submit', 'label' => 'Назначить эвакуатор'] : null, $ir);
        }

        if ($ir?->isTow()) {
            $chips = array_values(array_filter([$ir->planned_at?->translatedFormat('j M, H:i'), $ir->carrier, $ir->cost ? Money::rub($ir->cost) : null]));
            [$state, $hint, $plate] = match (true) {
                $ir->state === RequestState::New => [Step::CURRENT, 'Договориться с эвакуатором о дате и цене', ['kind' => 'submit', 'label' => 'Назначить']],
                $ir->state === RequestState::Scheduled => [Step::CURRENT, 'Когда эвакуатор погрузит ТС, отметить выезд', ['kind' => 'submit', 'label' => 'Выехали']],
                default => [Step::DONE, null, null],
            };
            if ($state === Step::CURRENT && ($ir->needsCall() || $callAgain)) {
                $state = Step::NEXT;
            }
            $steps[] = new Step('tow', 'Эвакуация', $state, $hint, $ir->started_at ?? $ir->planned_at, $state === Step::DONE ? $chips : [], $plate, $ir);
        }

        if ($v->accepted_at) {
            $chips = array_values(array_filter([$v->yard?->name.($v->spot ? ', '.$v->spot : ''), $ir?->doneBy?->shortName()]));
            $steps[] = new Step('intake', 'Принята', Step::DONE, null, $v->accepted_at, $chips, null, $ir?->state === RequestState::Done ? $ir : null);
        } elseif ($v->state === VehicleState::Cancelled) {
            $steps[] = new Step('cancelled', 'Не привезена', Step::DONE, $v->cancel_reason, $v->cancelled_at, [], null, null, true);
        } else {
            $ready = $ir && $ir->isOpen() && ! $ir->needsCall() && ! $callAgain && (! $ir->isTow() || $ir->state === RequestState::InProgress);
            $hint = $v->state === VehicleState::InTransit ? 'ТС в пути, принять по приезду: место, 6 фото, подпись' : 'Поставить на место, снять 6 фото, взять подпись';
            $steps[] = new Step('intake', 'Приём', $ready ? Step::CURRENT : Step::NEXT, $hint, $ir?->planned_at, [], $ready ? ['kind' => 'submit', 'label' => 'Принять'] : null, $ir?->isOpen() ? $ir : null);
        }

        // Что впереди — серым, чтобы было видно весь путь.
        if (! $v->accepted_at && $v->state !== VehicleState::Cancelled) {
            if ($billable) {
                $steps[] = new Step('report', 'Отчёт вендору', Step::NEXT);
            }
            $steps[] = new Step('storage', 'Хранение', Step::NEXT);
            $steps[] = new Step('release', 'Выдача', Step::NEXT);
        }

        if ($v->accepted_at && $billable) {
            $ok = $sent('Акт приёма');
            $steps[] = new Step('report', 'Отчёт вендору', $ok ? Step::DONE : Step::CURRENT, $ok ? null : 'Отправить вендору акт приёма и фото', null, [],
                $ok ? null : ['kind' => 'window', 'label' => 'Отправить', 'url' => $v->reportUrl('intake', "/cars/{$v->id}")]);
        }

        if ($v->accepted_at) {
            $releasing = $release?->isOpen() && $v->state === VehicleState::Stored;
            $storageState = $v->released_at || $releasing ? Step::DONE : ($v->state === VehicleState::Stored ? Step::CURRENT : Step::NEXT);
            $chips = array_values(array_filter([$v->sold_at ? 'продано '.$v->sold_at->translatedFormat('j M') : null, $v->pickup_name ? 'заберёт '.$v->pickup_name : null]));
            $steps[] = new Step('storage', 'Хранение', $storageState, 'Ждём письмо страховой о продаже', $v->sold_at, $chips,
                $storageState === Step::CURRENT ? ['kind' => 'spawn', 'label' => 'Выдать'] : null);

            $chips = array_values(array_filter([$release?->doneBy?->shortName(), $release?->note]));
            $steps[] = new Step('release', $v->released_at ? 'Выдана' : 'Выдача', $v->released_at ? Step::DONE : ($releasing ? Step::CURRENT : Step::NEXT),
                'Проверить долг и документ, снять фото, взять подпись', $v->released_at ?? $release?->planned_at, $v->released_at ? $chips : [],
                $releasing ? ['kind' => 'submit', 'label' => 'Выдать'] : null, $release?->isOpen() ? $release : ($v->released_at ? $release : null));

            if ($v->released_at && $billable) {
                $ok = $sent('Акт выдачи');
                $steps[] = new Step('report-release', 'Отчёт вендору', $ok ? Step::DONE : Step::CURRENT, $ok ? null : 'Отправить вендору акт выдачи и фото', null, [],
                    $ok ? null : ['kind' => 'window', 'label' => 'Отправить', 'url' => $v->reportUrl('release', "/cars/{$v->id}")]);
            }
        }

        // Текущий — последний из «своих текущих»; более ранние с делом, но не с формой — todo.
        $currents = array_keys(array_filter($steps, fn (Step $s) => $s->state === Step::CURRENT));
        foreach (array_slice($currents, 0, -1) as $i) {
            $steps[$i]->state = Step::TODO;
        }

        return $steps;
    }

    /** @param  list<Step>  $steps */
    public static function current(array $steps): ?Step
    {
        foreach ($steps as $s) {
            if ($s->isCurrent()) {
                return $s;
            }
        }

        return null;
    }

    /** Слово шага для строки списка: «звонок», «в пути», «на стоянке» — без запросов к ленте. */
    public static function word(Vehicle $v, Request $r): ?string
    {
        return match (true) {
            $r->needsCall() => 'звонок',
            $v->state === VehicleState::InTransit => 'в пути',
            $r->isTow() && $r->state === RequestState::Scheduled => 'эвакуатор '.($r->planned_at?->translatedFormat('j M') ?? 'назначен'),
            $r->isTow() && $r->state === RequestState::New => 'нужен эвакуатор',
            $v->state === VehicleState::Stored => 'на стоянке',
            $r->delivery === Delivery::Self => 'привезёт сам',
            default => null,
        };
    }
}
