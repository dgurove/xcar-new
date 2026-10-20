<?php

namespace App\Logistics\Actions;

use App\Logistics\Channel;
use App\Logistics\Depot;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Point;
use App\Logistics\Route;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Logistics\TransportKind;
use App\Logistics\TransportState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «+ Стоянка» в маршруте (20.10.2026, владелец: «в регионе до стоянки, потом межрегион до стоянки, потом довоз — у каждого
 * отрезка свой исполнитель»): отрезок A → B делится стоянкой на A → Стоянка и Стоянка → B. У первой половины остаются
 * исполнитель и цена (его «куда» — стоянка), вторая — «Впереди», без исполнителя: логист назначит своего заранее или по
 * ходу. Делить можно, пока отрезок не привезли — и тот, что уже в пути (исполнителю — что «куда» сменилось). Стоянка —
 * своя у логиста маршрута; вставляют логист и ведущие логистику.
 */
final class AddStop
{
    public function __invoke(Transport $leg, Depot $depot, User $by): Transport
    {
        if ($leg->channel !== Channel::Logist || ! $leg->logist || ! ($leg->hasLogist($by) || $by->leadsLogistics())) {
            throw ValidationException::withMessages(['depot_id' => 'Стоянки в маршруте ставит логист']);
        }
        if (! ($leg->isOpen() || $leg->state === TransportState::Planned)) {
            throw ValidationException::withMessages(['depot_id' => 'Отрезок уже '.mb_strtolower($leg->state->label())]);
        }
        if ($depot->logist_id !== $leg->logist_id || $depot->archived_at) {
            throw ValidationException::withMessages(['depot_id' => 'Какая стоянка']);
        }
        if ($leg->destination === Point::Depot && $leg->depot_id === $depot->id) {
            throw ValidationException::withMessages(['depot_id' => 'Отрезок и так на эту стоянку']);
        }

        $next = DB::transaction(function () use ($leg, $depot, $by) {
            Transport::where('route_id', $leg->route_id)->where('seq', '>', $leg->seq)->increment('seq');
            $after = Transport::where('route_id', $leg->route_id)->where('previous_id', $leg->id)->first();
            $next = Transport::create([
                'car_id' => $leg->car_id, 'origin' => $leg->origin, 'kind' => TransportKind::Move, 'channel' => Channel::Logist,
                'logist_id' => $leg->logist_id, 'state' => TransportState::Planned, 'route_id' => $leg->route_id, 'seq' => $leg->seq + 1,
                'current' => false, 'previous_id' => $leg->id,
                // Откуда — стоянка: кто отдаёт машину — тот, кто на ней (страница исполнителя и «Отправить» берут его контакт).
                'from_depot_id' => $depot->id, 'from_address' => $depot->label(), 'from_contact' => $depot->contact_name, 'from_phone' => $depot->phone,
                // Куда — то, что было у делимого отрезка.
                'destination' => $leg->destination, 'to_yard_id' => $leg->to_yard_id, 'to_address' => $leg->to_address,
                'depot_id' => $leg->destination === Point::Depot ? $leg->depot_id : null, 'to_contact' => $leg->to_contact, 'to_phone' => $leg->to_phone,
                'client_id' => $leg->client_id, 'created_by' => $by->id,
            ]);
            $after?->update(['previous_id' => $next->id]);
            $leg->update(['destination' => Point::Depot, 'depot_id' => $depot->id, 'to_yard_id' => null, 'to_address' => null,
                'to_contact' => $depot->contact_name, 'to_phone' => $depot->phone]);
            TransportEvent::record($leg, 'stop', $by, ['depot' => $depot->label()]);
            TransportEvent::record($next, 'opened', $by, ['from' => $depot->label()]);

            return $next;
        });
        // Исполнитель уже везёт — «куда» сменилось у него на глазах.
        if ($leg->isOpen()) {
            TransportMoved::dispatch($leg->fresh(), $by, 'route');
        }

        return $next;
    }
}
