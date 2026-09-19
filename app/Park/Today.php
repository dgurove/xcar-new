<?php

namespace App\Park;

use App\Park\Request as ParkRequest;
use App\Users\User;
use Illuminate\Support\Collection;

/**
 * Дела дня одной функцией — экрану «Сегодня» и утреннему дайджесту владельцу, чтобы числа сходились.
 * Секции: Просрочено · Связаться · Забрать · Принять · Выдать · Осмотреть, переставить ·
 * Отправить вендору · Стоят долго. Пустые не отдаются.
 *
 * @phpstan-type Section array{0: string, 1: ?string, 2: Collection, 3: string, 4: ?string}
 */
final class Today
{
    public const DAYS = ['today' => 'Сегодня', 'tomorrow' => 'Завтра', 'week' => 'Неделя', 'all' => 'Все'];

    /** @return array{sections: list<Section>, stats: array<string, int>} */
    public static function build(?User $user, string $day = 'today', bool $mine = false): array
    {
        [$from, $to] = match ($day) {
            'tomorrow' => [now()->addDay()->startOfDay(), now()->addDay()->endOfDay()],
            'week' => [now()->startOfDay(), now()->addDays(7)->endOfDay()],
            'all' => [null, null],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
        $requests = $user ? Scope::requests($user) : ParkRequest::query();
        $vehicles = fn () => $user ? Scope::vehicles($user) : Vehicle::query();

        $open = $requests->whereIn('state', RequestState::open())
            ->with(['vehicle.brand', 'vehicle.model', 'vehicle.vendor', 'vehicle.media', 'vehicle.yard', 'yard', 'assignee'])
            ->when($mine, fn ($q) => $q->where('assignee_id', $user?->id))
            ->orderByRaw('planned_at asc nulls last')->get();

        $overdue = $open->filter(fn (ParkRequest $r) => $r->isOverdue());
        $rest = $open->reject(fn (ParkRequest $r) => $r->isOverdue())->filter(fn (ParkRequest $r) => $day === 'all' || ! $r->planned_at || $r->planned_at->between($from, $to));

        $call = $rest->filter(fn (ParkRequest $r) => $r->isTow() && $r->state === RequestState::New && ! $r->planned_at);
        $fetch = $rest->filter(fn (ParkRequest $r) => $r->isTow() && ($r->state !== RequestState::New || $r->planned_at));
        $intake = $rest->filter(fn (ParkRequest $r) => $r->type === RequestType::Intake);
        $release = $rest->filter(fn (ParkRequest $r) => $r->type === RequestType::Release);
        $other = $rest->filter(fn (ParkRequest $r) => in_array($r->type, [RequestType::Inspection, RequestType::Move], true));

        // В пути — принять по приезде; «Мои» — чьи эвакуации.
        $transit = $vehicles()->where('state', VehicleState::InTransit)->with(['brand', 'model', 'vendor', 'media', 'requests'])->get()
            ->filter(fn (Vehicle $v) => ! $mine || $v->openRequest(RequestType::Tow)?->assignee_id === $user?->id);
        $stored = $vehicles()->where('state', VehicleState::Stored)->with(['brand', 'model', 'vendor', 'media', 'yard', 'offer', 'docs'])->orderBy('accepted_at')->get();
        $idle = $stored->filter(fn (Vehicle $v) => ($v->daysStored() ?? 0) >= Idle::warn());
        $docsDue = $stored->filter(fn (Vehicle $v) => $v->docsPending() && $v->accepted_at && $v->accepted_at->lt(now()->subDays(self::docsDays())));

        $yards = Yard::where('is_active', true)->withCount('storedVehicles')->get();

        return [
            'stats' => [
                'Ждут' => $open->count(), 'Просрочено' => $overdue->count(),
                'На стоянке' => $stored->count(), 'Свободно' => max(0, $yards->sum('capacity') - $yards->sum('stored_vehicles_count')),
            ],
            'sections' => array_values(array_filter([
                ['Просрочено', 'danger', $overdue, 'requests', '/requests?sort=planned'],
                ['Связаться', null, $call, 'requests', '/requests?preset=tow'],
                ['Забрать', null, $fetch, 'requests', '/requests?preset=tow'],
                ['Принять', null, $intake->concat($transit), 'mixed', '/requests?preset=intake'],
                ['Выдать', null, $release, 'requests', '/requests?preset=release'],
                ['Осмотреть, переставить', null, $other, 'requests', '/requests?preset=inspection'],
                ['Отправить вендору', null, $docsDue, 'vehicles', '/cars?docs=due'],
                ['Стоят долго', 'urgent', $idle, 'vehicles', '/cars?preset=stored'],
            ], fn ($s) => $s[2]->isNotEmpty())),
        ];
    }

    /** Через сколько дней после приёма неотправленные бумаги вендору — дело дня. */
    public static function docsDays(): int
    {
        return (int) config('xcar.park_docs_days', 3);
    }
}
