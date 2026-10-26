<?php

namespace App\Logistics\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\InvoiceState;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Point;
use App\Logistics\Route;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Logistics\TransportState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Убрать стоянку из маршрута (20.10.2026): отрезок на стоянку и отрезок за ней, который ещё «Впереди», склеиваются — первый
 * везёт сразу туда, куда вёз второй; второй удаляется вместе с его неоплаченным долгом логисту. Оплаченный — не трогаем.
 */
final class RemoveStop
{
    public function __construct(private VoidInvoice $void) {}

    public function __invoke(Transport $leg, User $by): Transport
    {
        if (! ($leg->hasLogist($by) || $by->leadsLogistics())) {
            throw ValidationException::withMessages(['depot_id' => 'Стоянки в маршруте убирает логист']);
        }
        $next = Route::after($leg);
        if ($leg->destination !== Point::Depot || ! $next || $next->state !== TransportState::Planned) {
            throw ValidationException::withMessages(['depot_id' => 'ТС уже на стоянке: убрать её нельзя']);
        }
        if (! ($leg->isOpen() || $leg->state === TransportState::Planned)) {
            throw ValidationException::withMessages(['depot_id' => 'Отрезок уже '.mb_strtolower($leg->state->label())]);
        }
        if ($next->invoice && (float) $next->invoice->paid > 0) {
            throw ValidationException::withMessages(['depot_id' => 'Отрезок за стоянкой уже оплачен логисту']);
        }
        $depot = $leg->depot?->label() ?? 'стоянка';
        DB::transaction(function () use ($leg, $next, $by, $depot) {
            if ($next->invoice && $next->invoice->state === InvoiceState::Issued) {
                ($this->void)($next->invoice, $by, 'Стоянку убрали из маршрута');
            }
            $leg->update(['destination' => $next->destination, 'depot_id' => $next->destination === Point::Depot ? $next->depot_id : null,
                'to_yard_id' => $next->to_yard_id, 'to_address' => $next->to_address, 'to_contact' => $next->to_contact, 'to_phone' => $next->to_phone]);
            Transport::where('route_id', $leg->route_id)->where('previous_id', $next->id)->update(['previous_id' => $leg->id]);
            $next->events()->delete();
            $next->delete();
            Transport::where('route_id', $leg->route_id)->where('seq', '>', $leg->seq)->decrement('seq');
            TransportEvent::record($leg, 'unstop', $by, ['depot' => $depot]);
        });
        if ($leg->isOpen()) {
            TransportMoved::dispatch($leg->fresh(), $by, 'route');
        }

        return $leg;
    }
}
