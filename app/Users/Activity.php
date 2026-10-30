<?php

namespace App\Users;

use App\Cars\Car;
use App\Logistics\TransportEvent;
use App\Offers\OfferEvent;
use App\Park\VehicleEvent;
use App\Support\Plural;
use App\Support\Surface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Активность человека за период (30.10.2026, владелец: «когда заходил, что делал и с какого устройства — показать
 * начальнику»): посещения (`Visit`) и действия из журналов, что уже есть, — история машины (`car_events`: продажа,
 * закупка, подтверждения), дело ТС парковки, вывоз, сообщения чатов, открытые предложения. Своих записей о действиях
 * нет: строка — то же, что в истории объекта, теми же словами (`OfferEvent::text`, `VehicleEvent::text`,
 * `TransportEvent::what`). Дни, где не было ничего, — промежутком «не заходил».
 */
final class Activity
{
    /** Периоды экрана, дней. */
    public const PERIODS = [7, 30, 90];

    /** Строк одного журнала за период — не больше. */
    private const LIMIT = 400;

    public readonly Carbon $from;

    /** @var Collection<int, Visit> */
    public readonly Collection $visits;

    /** @var Collection<int, array{at: Carbon, key: string, title: string, text: string, href: ?string, surface: Surface}> */
    public readonly Collection $actions;

    public function __construct(public readonly User $user, public readonly int $days = 30)
    {
        $this->from = today()->subDays($days - 1);
        $this->visits = Visit::where('user_id', $user->id)->where('last_at', '>=', $this->from)->orderByDesc('last_at')->limit(self::LIMIT)->get();
        $this->actions = $this->actions()->sortByDesc('at')->values();
    }

    /** Последнее действие за всё время (не только за период) — одной строкой. */
    public function lastAction(): ?array
    {
        return $this->actions->first() ?? (new self($this->user, 3650))->actions->first();
    }

    /** Последний заход: отметка «в сети» или журнал посещений — что позже. */
    public function lastSeen(): ?Carbon
    {
        return once(fn () => collect([$this->user->seen_at, Visit::where('user_id', $this->user->id)->max('last_at')])
            ->filter()->map(fn ($at) => Carbon::parse($at))->max());
    }

    /** С какого устройства был последний заход. */
    public function lastVisit(): ?Visit
    {
        return Visit::where('user_id', $this->user->id)->latest('last_at')->first();
    }

    /** Дней без захода — по последней отметке; 0 — сегодня. */
    public function absentDays(): ?int
    {
        return ($at = $this->lastSeen()) ? (int) $at->copy()->startOfDay()->diffInDays(today()) : null;
    }

    /**
     * По дням периода, от первого к сегодня: число действий, был ли заход, минут в системе. День с заходом без журнала
     * посещений (до 30.10.2026) — по отметке «в сети» и по действиям.
     *
     * @return Collection<string, array{day: Carbon, actions: int, visited: bool, seconds: int}>
     */
    public function perDay(): Collection
    {
        return once(fn () => $this->days());
    }

    private function days(): Collection
    {
        $actions = $this->actions->countBy(fn ($a) => $a['at']->toDateString());
        $visits = $this->visits->groupBy(fn (Visit $v) => $v->last_at->toDateString());
        $seen = $this->user->seen_at?->toDateString();

        return collect(range(0, $this->days - 1))->mapWithKeys(function (int $i) use ($actions, $visits, $seen) {
            $day = $this->from->copy()->addDays($i);
            $key = $day->toDateString();

            return [$key => [
                'day' => $day,
                'actions' => $actions[$key] ?? 0,
                'visited' => isset($visits[$key]) || isset($actions[$key]) || $key === $seen,
                'seconds' => (int) ($visits[$key] ?? collect())->sum(fn (Visit $v) => $v->seconds()),
            ]];
        });
    }

    /** Дней с заходом за период. */
    public function activeDays(): int
    {
        return $this->perDay()->where('visited', true)->count();
    }

    /** Время в системе — только по журналу (`Visit::seconds`). */
    public function seconds(): int
    {
        return (int) $this->visits->sum(fn (Visit $v) => $v->seconds());
    }

    /**
     * Лента по дням, свежие сверху: в дне — заходы и действия по времени, подряд идущие действия с одним объектом —
     * одной строкой. Между днями с делами — промежуток «не заходил N дней», от последнего дня до сегодня тоже.
     *
     * @return list<array{kind: 'day', day: Carbon, items: Collection}|array{kind: 'gap', from: Carbon, to: Carbon, days: int, absent: bool}>
     */
    public function timeline(): array
    {
        $byDay = collect();
        foreach ($this->visits as $v) {
            $byDay[$v->last_at->toDateString()] = ($byDay[$v->last_at->toDateString()] ?? collect())->push(['kind' => 'visit', 'at' => $v->last_at, 'visit' => $v]);
        }
        foreach ($this->actions as $a) {
            $byDay[$a['at']->toDateString()] = ($byDay[$a['at']->toDateString()] ?? collect())->push(['kind' => 'action', 'at' => $a['at']] + $a);
        }

        $out = [];
        $next = today()->addDay();
        foreach ($byDay->sortKeysDesc() as $key => $items) {
            $day = Carbon::parse($key);
            $this->gap($out, $day->copy()->addDay(), $next->copy()->subDay());
            $out[] = ['kind' => 'day', 'day' => $day, 'items' => $this->collapse($items->sortByDesc('at')->values())];
            $next = $day;
        }
        $this->gap($out, $this->from->copy(), $next->copy()->subDay());

        return $out;
    }

    /**
     * Промежуток без дел — со второго пустого дня (один день пропуска — выходной, не событие) и сегодняшний. `absent` —
     * точно не заходил: после последней отметки «в сети» или там, где журнал посещений полный; иначе — только «без
     * действий» (смотреть страницы мог, журнала тогда не было).
     */
    private function gap(array &$out, Carbon $from, Carbon $to): void
    {
        $days = (int) $from->diffInDays($to) + 1;
        if ($from->lte($to) && ($days >= 2 || $to->isToday())) {
            $seen = $this->lastSeen()?->toDateString();
            $absent = ! $seen || $seen < $from->toDateString() || $from->toDateString() >= Visit::SINCE;
            $out[] = ['kind' => 'gap', 'from' => $from, 'to' => $to, 'days' => $days, 'absent' => $absent];
        }
    }

    /** Подряд идущие действия с одним объектом — одной строкой со всеми фразами (без повторов) и временем первого. */
    private function collapse(Collection $items): Collection
    {
        $rows = [];
        foreach ($items as $item) {
            $last = end($rows);
            if ($item['kind'] === 'action' && $last && $last['kind'] === 'action' && $last['key'] === $item['key']) {
                $rows[array_key_last($rows)]['texts'][] = $item['text'];
                $rows[array_key_last($rows)]['since'] = $item['at'];

                continue;
            }
            $rows[] = $item + ($item['kind'] === 'action' ? ['texts' => [$item['text']], 'since' => $item['at']] : []);
        }

        return collect($rows)->map(fn ($r) => isset($r['texts']) ? ['texts' => array_values(array_unique(array_reverse($r['texts'])))] + $r : $r);
    }

    /** Действия из всех журналов — одним видом. */
    private function actions(): Collection
    {
        $id = $this->user->id;
        $rows = collect();

        // История машины: продажа, закупка, подтверждения, сделка.
        $events = OfferEvent::where('user_id', $id)->where('created_at', '>=', $this->from)->latest('id')->limit(self::LIMIT)->get();
        $numbers = DB::table('offers')->whereIn('id', $events->pluck('offer_id')->filter()->unique())->pluck('number', 'id');
        foreach ($events as $e) {
            $number = $numbers[$e->offer_id] ?? null;
            $rows->push(['at' => $e->created_at, 'car' => $e->car_id, 'number' => $number, 'key' => 'car:'.$e->car_id, 'text' => $e->text(), 'surface' => Surface::Crm,
                'href' => $number ? Surface::Crm->url('/offers/'.$number) : null]);
        }

        // Дело ТС парковки.
        $events = VehicleEvent::where('user_id', $id)->where('created_at', '>=', $this->from)->latest('id')->limit(self::LIMIT)->get();
        $cars = DB::table('park_vehicles')->whereIn('id', $events->pluck('vehicle_id')->unique())->pluck('car_id', 'id');
        foreach ($events as $e) {
            $rows->push(['at' => $e->created_at, 'car' => $cars[$e->vehicle_id] ?? null, 'key' => 'park:'.$e->vehicle_id, 'text' => $e->text(), 'surface' => Surface::Park,
                'href' => Surface::Park->url('/cars/'.$e->vehicle_id)]);
        }

        // Вывоз.
        $events = TransportEvent::where('by_id', $id)->where('created_at', '>=', $this->from)->latest('id')->limit(self::LIMIT)->get();
        $cars = DB::table('transports')->whereIn('id', $events->pluck('transport_id')->unique())->pluck('car_id', 'id');
        foreach ($events as $e) {
            $rows->push(['at' => $e->created_at, 'car' => $cars[$e->transport_id] ?? null, 'key' => 'transport:'.$e->transport_id, 'text' => $e->what(),
                'surface' => Surface::Logistics, 'href' => Surface::Logistics->url('/transports/'.$e->transport_id)]);
        }

        // Сообщения — строка на чат и час: «Написал 3 сообщения».
        $chats = DB::table('chat_messages as m')->join('chats as c', 'c.id', '=', 'm.chat_id')->leftJoin('offers as o', 'o.id', '=', 'c.offer_id')
            ->where('m.author_id', $id)->whereNull('m.deleted_at')->where('m.created_at', '>=', $this->from)
            ->groupByRaw("m.chat_id, date_trunc('hour', m.created_at), o.car_id, c.manager_id")
            ->selectRaw('m.chat_id, max(m.created_at) as at, count(*) as n, o.car_id, c.manager_id')->limit(self::LIMIT)->get();
        foreach ($chats as $c) {
            $rows->push(['at' => Carbon::parse($c->at), 'car' => $c->car_id, 'title' => $c->car_id ? null : 'Чат', 'key' => 'chat:'.$c->chat_id,
                'text' => 'Написал '.$c->n.' '.Plural::of($c->n, ['сообщение', 'сообщения', 'сообщений']).' в чат', 'surface' => Surface::Crm,
                'href' => Surface::Crm->url('/work/chats/'.$c->chat_id)]);
        }

        // Открытые предложения — строка на час: «Открыл 12 предложений».
        $reads = DB::table('offer_reads')->where('user_id', $id)->where('read_at', '>=', $this->from)
            ->groupByRaw("date_trunc('hour', read_at)")->selectRaw('max(read_at) as at, count(*) as n')->get();
        foreach ($reads as $r) {
            $rows->push(['at' => Carbon::parse($r->at), 'car' => null, 'title' => 'Предложения', 'key' => 'reads:'.$r->at,
                'text' => 'Открыл '.$r->n.' '.Plural::of($r->n, ['предложение', 'предложения', 'предложений']), 'surface' => Surface::Site, 'href' => null]);
        }

        $titles = Car::with(['brand', 'model'])->whereIn('id', $rows->pluck('car')->filter()->unique())->get()->mapWithKeys(fn (Car $c) => [$c->id => $c->titleWithYear() ?: 'ТС']);

        // Машина без марки (пустой черновик) — номером предложения.
        return $rows->map(fn ($r) => ['title' => $r['title'] ?? (($titles[$r['car']] ?? 'ТС') === 'ТС' && ($r['number'] ?? null) ? 'Предложение № '.$r['number'] : ($titles[$r['car']] ?? 'ТС'))] + $r)
            ->filter(fn ($r) => $r['text'] !== '');
    }
}
