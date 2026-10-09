<?php

namespace App\Park;

use App\Mail\Extraction\Intent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Таймлайн дела: Письмо → Приём → Отчёт вендору → Хранение → Выдача → Отчёт вендору; между хранением и выдачей —
 * отказы покупателей, если они были. Звонка, эвакуации и даты приезда нет (владелец 10.10.2026: «тут чисто приём и
 * всё»): пока ТС ждут, текущий шаг — приём.
 * Каждый шаг решает своё состояние сам; текущий — последний из «своих текущих», остальные такие — todo
 * (действие есть, но форма не раскрыта). Подсказки — одной строкой, без точек.
 */
final class Timeline
{
    /** @return list<Step> */
    public static function for(Vehicle $v, ?Request $open, bool $hasLetters, Collection $events, ?Collection $asks = null, ?Pass $pass = null): array
    {
        $steps = [];
        $ir = $open?->type === RequestType::Intake ? $open
            : $v->requests->first(fn (Request $r) => $r->type === RequestType::Intake && $r->state === RequestState::Done);
        $release = $open?->type === RequestType::Release ? $open : $v->requests->first(fn (Request $r) => $r->type === RequestType::Release && $r->state === RequestState::Done);
        $billable = $v->vendor?->kind->billable() ?? false;
        $sent = fn (string $what) => $events->contains(fn ($e) => $e->type === EventType::ReportSent && ($e->payload['what'] ?? null) === $what);

        $steps[] = new Step('letter', $hasLetters ? 'Письмо' : 'Заведена руками', Step::DONE, at: $v->created_at);

        if ($v->accepted_at) {
            $chips = array_values(array_filter([$v->yard?->name.($v->spot ? ', '.$v->spot : ''), $ir?->doneBy?->shortName()]));
            $steps[] = new Step('intake', 'Принята', Step::DONE, null, $v->accepted_at, $chips, null, $ir?->state === RequestState::Done ? $ir : null);
        } elseif ($v->state === VehicleState::Cancelled) {
            $steps[] = new Step('cancelled', 'Не привезена', Step::DONE, $v->cancel_reason, $v->cancelled_at, [], null, null, true);
        } else {
            $ready = $ir && $ir->isOpen();
            // Заявки нет (отменили) — шаг всё равно текущий: «Принять» заводит заявку и открывает форму.
            $orphan = ! $ready && $v->state === VehicleState::Expected;
            $steps[] = new Step('intake', 'Нужно принять', Step::CURRENT, $orphan ? 'Заявки нет: принять, когда привезут' : 'Поставить на место и снять фото', null, [],
                $ready ? ['kind' => 'submit', 'label' => 'Принять'] : ['kind' => 'spawn', 'label' => 'Принять'], $ready ? $ir : null);
        }

        // Что впереди — серым, чтобы было видно весь путь.
        if (! $v->accepted_at && $v->state !== VehicleState::Cancelled) {
            if ($billable) {
                $steps[] = new Step('report', 'Отчёт', Step::NEXT);
            }
            $steps[] = new Step('storage', 'Хранение', Step::NEXT);
            $steps[] = new Step('release', 'Выдача', Step::NEXT);
        }

        if ($v->accepted_at && $billable) {
            $ok = $sent('Акт приёма');
            $steps[] = new Step('report', $ok ? 'Отчёт отправлен' : 'Нужно отправить отчёт', $ok ? Step::DONE : Step::CURRENT, $ok ? null : 'Отправить вендору акт приёма и фото', null, [],
                $ok ? null : ['kind' => 'window', 'label' => 'Отправить', 'url' => $v->reportUrl('intake', "/cars/{$v->id}")]);
        }

        // Заведена без парковки (по письмам или по факту): сначала сказать, где стоит — хранение считается по площадке.
        // Продана и ждёт выдачи — выдача важнее, парковка остаётся делом без формы.
        $noYard = $v->state === VehicleState::Stored && ! $v->yard_id;
        if ($noYard) {
            $steps[] = new Step('yard', 'Нужно указать парковку', Step::CURRENT, null, null, [], ['kind' => 'submit', 'label' => 'Указать']);
        }

        if ($v->accepted_at) {
            $releasing = $release?->isOpen() && $v->state === VehicleState::Stored;
            $storageState = $v->released_at || $releasing ? Step::DONE : ($v->state === VehicleState::Stored && ! $noYard ? Step::CURRENT : Step::NEXT);
            $chips = array_values(array_filter([$v->sold_at ? 'на выдачу с '.$v->sold_at->translatedFormat('j M') : null, $v->pickup_name ? 'заберёт '.$v->pickup_name : null]));
            $steps[] = new Step('storage', $storageState === Step::CURRENT ? 'На хранении' : 'Хранение', $storageState,
                $storageState === Step::CURRENT ? ($v->sold_at ? 'На выдачу, ждём покупателя за ТС' : 'Вендор пришлёт письмо, что ТС реализовано, шаг сменится сам; когда за ТС приедут, выдайте') : null,
                $v->sold_at, $chips, $storageState === Step::CURRENT ? ['kind' => 'spawn', 'label' => 'Выдать'] : null);

            // Покупатель приехал и не взял: продажа снята, ТС снова просто стоит — исход виден шагом, а не заметкой.
            foreach ($events->where('type', EventType::ReleaseRefused)->sortBy('created_at') as $refusal) {
                $steps[] = new Step('refused', 'Покупатель отказался', Step::DONE, null, $refusal->created_at,
                    array_values(array_filter([$refusal->payload['note'] ?? null, $refusal->user?->shortName()])), danger: true);
            }

            // Выдача по QR: покупатель заполняет анкету по ссылке, страховая подтверждает — шаг между хранением и выдачей.
            // Никогда не текущий: форма выдачи остаётся на месте, а шаг показывает, где анкета, и даёт «Страховая подтвердила».
            // У выданной без QR ждать анкету уже нечего — шага нет.
            if ($v->releasesByQr() && ($v->sold_at || $releasing || ($v->released_at && $pass))) {
                $linkAt = $events->where('type', EventType::PickupLinkSent)->max('created_at');
                $steps[] = match (true) {
                    ! $pass => new Step('buyer', 'Ждём анкету покупателя', $v->released_at ? Step::NEXT : Step::TODO, null, null,
                        $linkAt ? ['ссылка отправлена '.Carbon::parse($linkAt)->translatedFormat('j M')] : []),
                    $pass->isConfirmed() => new Step('buyer', 'Покупатель подтверждён', Step::DONE, null, $pass->confirmed_at,
                        array_values(array_filter([$pass->name, 'заберёт '.$pass->pickup_on->translatedFormat('j M'), $pass->confirm_note, $pass->confirmer?->shortName()]))),
                    default => new Step('buyer', 'Покупатель заполнил анкету', Step::TODO, null, $pass->submitted_at),
                };
            }

            $chips = array_values(array_filter([$release?->doneBy?->shortName(), $release?->note]));
            $steps[] = new Step('release', $v->released_at ? 'Выдана' : ($releasing ? 'Нужно выдать' : 'Выдача'), $v->released_at ? Step::DONE : ($releasing ? Step::CURRENT : Step::NEXT),
                $v->releasesByQr() ? 'Отсканировать QR покупателя и проверить долг' : 'Проверить долг', $v->released_at, $v->released_at ? $chips : [],
                $releasing ? ['kind' => 'submit', 'label' => 'Выдать'] : null, $release?->isOpen() ? $release : ($v->released_at ? $release : null));

            if ($v->released_at && $billable) {
                $ok = $sent('Акт выдачи');
                $steps[] = new Step('report-release', $ok ? 'Отчёт отправлен' : 'Нужно отправить отчёт', $ok ? Step::DONE : Step::CURRENT, $ok ? null : 'Отправить вендору акт выдачи и фото', null, [],
                    $ok ? null : ['kind' => 'window', 'label' => 'Отправить', 'url' => $v->reportUrl('release', "/cars/{$v->id}")]);
            }
        }

        // Текущий — последний из «своих текущих»; более ранние с делом, но не с формой — todo.
        $currents = array_keys(array_filter($steps, fn (Step $s) => $s->state === Step::CURRENT));
        foreach (array_slice($currents, 0, -1) as $i) {
            $steps[$i]->state = Step::TODO;
        }

        // Ветка, которая ждёт ответа (осмотр, бумаги, вопрос), — шаг с делом рядом с текущим; текущий с формой
        // остаётся на месте. Правило одно на почту и дело: последнее письмо ветки их и без нашего ответа.
        if ($asks && $asks->isNotEmpty()) {
            $pos = count($steps);
            foreach ($steps as $i => $s) {
                if ($s->state !== Step::DONE) {
                    $pos = $i;
                    break;
                }
            }
            $insert = $asks->map(fn ($m) => new Step('reply', Intent::from($m->intent)->title(), Step::TODO, null, $m->date_at, [$m->from_name ?: $m->from_email],
                ['kind' => 'window', 'label' => 'Ответить', 'url' => '/mail/'.$m->thread_id.'/window'], ask: $m))->values()->all();
            array_splice($steps, $pos, 0, $insert);
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
}
