<?php

namespace App\Park;

use App\Park\Request as ParkRequest;
use Illuminate\Support\Collection;

/**
 * Дела дня одной функцией — утреннему дайджесту владельцу (`park:digest`). Экрана «Сегодня» нет:
 * те же дела на `/requests` пресетами (Просрочено · Связаться · типы) и на `/cars` (Бумаги вендору · Стоят долго).
 * Секции: Просрочено · Связаться · Забрать · Принять · Выдать · Осмотреть, переставить · Отправить вендору · Стоят долго.
 * Пустые не отдаются.
 *
 * @phpstan-type Section array{0: string, 1: Collection, 2: string}
 */
final class Today
{
    /** @return array{sections: list<Section>, stored: int} */
    public static function build(): array
    {
        $open = ParkRequest::query()->whereIn('state', RequestState::open())->with('vehicle')->orderByRaw('planned_at asc nulls last')->get();

        $overdue = $open->filter(fn (ParkRequest $r) => $r->isOverdue());
        $rest = $open->reject(fn (ParkRequest $r) => $r->isOverdue())->filter(fn (ParkRequest $r) => ! $r->planned_at || $r->planned_at->isToday());

        $call = $rest->filter(fn (ParkRequest $r) => $r->needsCall());
        $fetch = $rest->filter(fn (ParkRequest $r) => $r->isTow() && ! $r->needsCall());
        $intake = $rest->filter(fn (ParkRequest $r) => $r->type === RequestType::Intake && ! $r->needsCall());
        $release = $rest->filter(fn (ParkRequest $r) => $r->type === RequestType::Release);
        $other = $rest->filter(fn (ParkRequest $r) => in_array($r->type, [RequestType::Inspection, RequestType::Move], true));

        $transit = Vehicle::where('state', VehicleState::InTransit)->get();
        $stored = Vehicle::where('state', VehicleState::Stored)->with('docs')->get();
        $idle = $stored->filter(fn (Vehicle $v) => ($v->daysStored() ?? 0) >= Idle::warn());
        $docsDue = $stored->filter(fn (Vehicle $v) => $v->docsPending() && $v->accepted_at && $v->accepted_at->lt(now()->subDays(self::docsDays())));

        return [
            'stored' => $stored->count(),
            'sections' => array_values(array_filter([
                ['Просрочено', $overdue, '/requests?preset=overdue'],
                ['Связаться', $call, '/requests?preset=call'],
                ['Забрать', $fetch, '/requests?preset=tow'],
                ['Принять', $intake->concat($transit), '/requests?preset=intake'],
                ['Выдать', $release, '/requests?preset=release'],
                ['Осмотреть, переставить', $other, '/requests?preset=inspection'],
                ['Отправить вендору', $docsDue, '/cars?docs=due'],
                ['Стоят долго', $idle, '/cars?preset=idle'],
            ], fn ($s) => $s[1]->isNotEmpty())),
        ];
    }

    /** Через сколько дней после приёма неотправленные бумаги вендору — дело дня. */
    public static function docsDays(): int
    {
        return (int) config('xcar.park_docs_days', 3);
    }
}
