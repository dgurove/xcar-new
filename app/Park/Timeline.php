<?php

namespace App\Park;

use App\Mail\Extraction\Intent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Таймлайн дела: Письмо → Звонок → Эвакуация → Приём → Отчёт вендору → Хранение → Выдача → Отчёт вендору;
 * между хранением и выдачей — отказы покупателей, если они были.
 * Каждый шаг решает своё состояние сам; текущий — последний из «своих текущих», остальные такие — todo
 * (действие есть, но форма не раскрыта). Подсказки — одной строкой, без точек.
 */
final class Timeline
{
    /** @return list<Step> */
    public static function for(Vehicle $v, ?Request $open, bool $hasLetters, Collection $events, bool $callAgain = false, ?Collection $asks = null, ?Pass $pass = null): array
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
            $steps[] = new Step('call', $current ? 'Нужно позвонить' : ($ir->contacted_at ? 'Позвонили' : 'Не дозвонились'), $current ? Step::CURRENT : Step::DONE, $current ? 'Позвонить страхователю, узнать кто и когда привезёт ТС' : null, $ir->contacted_at, $chips,
                $current ? ['kind' => 'submit', 'label' => 'Назначить эвакуатор'] : null, $ir);
        }

        if ($ir?->isTow()) {
            $chips = array_values(array_filter([$ir->planned_at?->translatedFormat('j M, H:i'), $ir->from_address]));
            [$state, $title, $hint, $plate] = match (true) {
                $ir->state === RequestState::New => [Step::CURRENT, 'Нужен эвакуатор', 'Договориться с эвакуатором о дате', ['kind' => 'submit', 'label' => 'Назначить']],
                $ir->state === RequestState::Scheduled => [Step::CURRENT, 'Эвакуатор назначен', 'Когда эвакуатор погрузит ТС, отметить выезд', ['kind' => 'submit', 'label' => 'Выехали']],
                default => [Step::DONE, 'Эвакуатор выехал', null, null],
            };
            if ($state === Step::CURRENT && ($ir->needsCall() || $callAgain)) {
                [$state, $title] = [Step::NEXT, 'Эвакуация'];
            }
            $steps[] = new Step('tow', $title, $state, $hint, $ir->started_at ?? $ir->planned_at, $state === Step::DONE ? $chips : [], $plate, $ir);
        }

        if ($v->accepted_at) {
            $chips = array_values(array_filter([$v->yard?->name.($v->spot ? ', '.$v->spot : ''), $ir?->doneBy?->shortName()]));
            $steps[] = new Step('intake', 'Принята', Step::DONE, null, $v->accepted_at, $chips, null, $ir?->state === RequestState::Done ? $ir : null);
        } elseif ($v->state === VehicleState::Cancelled) {
            $steps[] = new Step('cancelled', 'Не привезена', Step::DONE, $v->cancel_reason, $v->cancelled_at, [], null, null, true);
        } else {
            $ready = $ir && $ir->isOpen() && ! $ir->needsCall() && ! $callAgain && (! $ir->isTow() || $ir->state === RequestState::InProgress);
            $hint = $v->state === VehicleState::InTransit ? 'ТС в пути, принять по приезду: место, 6 фото, подпись' : 'Поставить на место, снять 6 фото, взять подпись';
            // Заявки нет (отменили) — шаг всё равно текущий: «Принять» заводит заявку и открывает форму.
            $orphan = ! $ir?->isOpen() && $v->state === VehicleState::Expected;
            $steps[] = new Step('intake', $ready || $orphan ? 'Нужно принять' : 'Приём', $ready || $orphan ? Step::CURRENT : Step::NEXT, $orphan ? 'Заявки нет: принять, когда привезут' : $hint, $ir?->planned_at, [],
                $ready ? ['kind' => 'submit', 'label' => 'Принять'] : ($orphan ? ['kind' => 'spawn', 'label' => 'Принять'] : null), $ir?->isOpen() ? $ir : null);
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
            $steps[] = new Step('yard', 'Нужно указать парковку', Step::CURRENT, 'Где стоит ТС', null, [], ['kind' => 'submit', 'label' => 'Указать']);
        }

        if ($v->accepted_at) {
            $releasing = $release?->isOpen() && $v->state === VehicleState::Stored;
            $storageState = $v->released_at || $releasing ? Step::DONE : ($v->state === VehicleState::Stored && ! $noYard ? Step::CURRENT : Step::NEXT);
            $chips = array_values(array_filter([$v->sold_at ? 'продано '.$v->sold_at->translatedFormat('j M') : null, $v->pickup_name ? 'заберёт '.$v->pickup_name : null]));
            $steps[] = new Step('storage', $storageState === Step::CURRENT ? 'На хранении' : 'Хранение', $storageState,
                $storageState === Step::CURRENT ? ($v->sold_at ? 'Продана, ждём покупателя за ТС' : 'Страховая пришлёт письмо о продаже, шаг сменится сам; когда за ТС приедут, выдайте') : null,
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
                $v->releasesByQr() ? 'Отсканировать QR покупателя и проверить долг' : 'Проверить долг', $v->released_at ?? $release?->planned_at, $v->released_at ? $chips : [],
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

    /** Слово шага для строки списка: «звонок», «в пути», «на стоянке» — без запросов к ленте. */
    public static function word(Vehicle $v, Request $r): ?string
    {
        return match (true) {
            $r->needsCall() => 'нужно позвонить',
            $v->state === VehicleState::InTransit => 'в пути',
            $r->isTow() && $r->state === RequestState::Scheduled => 'эвакуатор назначен'.($r->planned_at ? ' на '.$r->planned_at->translatedFormat('j M') : ''),
            $r->isTow() && $r->state === RequestState::New => 'нужен эвакуатор',
            $v->state === VehicleState::Stored => 'на парковке',
            $r->delivery === Delivery::Self => 'привезёт сам',
            default => null,
        };
    }
}
