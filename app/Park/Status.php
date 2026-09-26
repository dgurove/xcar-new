<?php

namespace App\Park;

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
            return $say(mb_strtolower($v->state->label()).($v->state === VehicleState::Released && $v->released_at ? ' '.$v->released_at->translatedFormat('j M') : ''));
        }
        if ($v->waiting_count ?? 0) {
            return $say('ответить на письмо', true);
        }
        $open = $v->requests->filter(fn (Request $r) => $r->isOpen());
        $req = $open->firstWhere('type', RequestType::Release) ?? $open->firstWhere('type', RequestType::Move) ?? $open->first();
        $when = fn (Request $r) => $r->planned_at?->translatedFormat('j M');
        if ($req) {
            $late = $req->isOverdue();
            $future = $req->planned_at && ! $late && ! $req->planned_at->isToday();

            return match ($req->type) {
                RequestType::Release => $future ? $say('выдача '.$when($req)) : $say('выдать'.($req->planned_at ? ' '.$when($req) : ''), true, $late),
                RequestType::Move => $say('переставить'.($req->yard ? ' на «'.$req->yard->name.'»' : ''), true, $late),
                RequestType::Inspection => $future ? $say('осмотр '.$when($req)) : $say('осмотр'.($req->planned_at ? ' '.$when($req) : ''), true, $late),
                default => $say(mb_strtolower($req->type->label()), true, $late),
            };
        }
        if ($v->sold_at) {
            return $say('продана, ждём покупателя');
        }
        if (! $v->yard_id) {
            return $say('указать парковку', true);
        }

        return $say('на парковке');
    }
}
