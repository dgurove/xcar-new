<?php

namespace App\Http\Park;

use App\Park\Idle;
use App\Park\Request as ParkRequest;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Scope;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use Illuminate\Http\Request;

/**
 * Рабочий стол стоянки: что просрочено, кому позвонить, что забрать, принять,
 * выдать — секциями на выбранный день; пустая секция не рисуется. Всё в адресе:
 * `den` — сегодня / завтра / неделя / все, `moi` — только мои.
 */
class TodayController
{
    public const DAYS = ['today' => 'Сегодня', 'tomorrow' => 'Завтра', 'week' => 'Неделя', 'all' => 'Все'];

    public function index(Request $request)
    {
        $user = $request->user();
        $day = array_key_exists($request->query('day', ''), self::DAYS) ? $request->query('day') : 'today';
        $mine = $request->boolean('mine');
        [$from, $to] = match ($day) {
            'tomorrow' => [now()->addDay()->startOfDay(), now()->addDay()->endOfDay()],
            'week' => [now()->startOfDay(), now()->addDays(7)->endOfDay()],
            'all' => [null, null],
            default => [now()->startOfDay(), now()->endOfDay()],
        };

        $open = Scope::requests($user)->whereIn('state', RequestState::open())
            ->with(['vehicle.brand', 'vehicle.model', 'vehicle.vendor', 'vehicle.media', 'vehicle.yard', 'yard', 'assignee'])
            ->when($mine, fn ($q) => $q->where('assignee_id', $user->id))
            ->orderByRaw('planned_at asc nulls last')->get();

        $overdue = $open->filter(fn (ParkRequest $r) => $r->isOverdue());
        $inWindow = fn (ParkRequest $r) => ! $r->isOverdue() && ($day === 'all' || ! $r->planned_at || ($r->planned_at->between($from, $to)));
        $rest = $open->reject(fn (ParkRequest $r) => $r->isOverdue())->filter($inWindow);

        $call = $rest->filter(fn (ParkRequest $r) => $r->isTow() && $r->state === RequestState::New && ! $r->planned_at);
        $fetch = $rest->filter(fn (ParkRequest $r) => $r->isTow() && ($r->state !== RequestState::New || $r->planned_at));
        $intake = $rest->filter(fn (ParkRequest $r) => $r->type === RequestType::Intake);
        $release = $rest->filter(fn (ParkRequest $r) => $r->type === RequestType::Release);
        $other = $rest->filter(fn (ParkRequest $r) => in_array($r->type, [RequestType::Inspection, RequestType::Move], true));

        $transit = Scope::vehicles($user)->where('state', VehicleState::InTransit)->with(['brand', 'model', 'vendor', 'media'])->get();
        $stored = Scope::vehicles($user)->where('state', VehicleState::Stored)->with(['brand', 'model', 'vendor', 'media', 'yard', 'offer', 'docs'])->orderBy('accepted_at')->get();
        $idle = $stored->filter(fn (Vehicle $v) => ($v->daysStored() ?? 0) >= Idle::WARN);
        $docsDue = $stored->filter(fn (Vehicle $v) => $v->docsPending() && $v->accepted_at && $v->accepted_at->lt(now()->subDays(3)));

        $yards = Yard::where('is_active', true)->withCount('storedVehicles')->get();

        return view('park.today', [
            'day' => $day, 'days' => self::DAYS, 'mine' => $mine,
            'stats' => [
                'Ждут' => $open->count(), 'Просрочено' => $overdue->count(),
                'На стоянке' => $stored->count(), 'Свободно' => max(0, $yards->sum('capacity') - $yards->sum('stored_vehicles_count')),
            ],
            'sections' => array_filter([
                ['Просрочено', 'danger', $overdue, 'requests'],
                ['Связаться', null, $call, 'requests'],
                ['Забрать', null, $fetch, 'requests'],
                ['Принять', null, $intake->concat($transit->map(fn ($v) => $v)), 'mixed'],
                ['Выдать', null, $release, 'requests'],
                ['Осмотреть, переставить', null, $other, 'requests'],
                ['Отправить вендору', null, $docsDue, 'vehicles'],
                ['Стоят долго', 'urgent', $idle, 'vehicles'],
            ], fn ($s) => $s[2]->isNotEmpty()),
        ]);
    }
}
